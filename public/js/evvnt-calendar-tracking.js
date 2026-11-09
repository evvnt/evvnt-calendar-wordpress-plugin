/**
 * Snowplow analytics for the server-rendered calendar and widget, as the discovery JS
 * plugin's EvvntTracker: a page view when a calendar or widget scrolls into view, event
 * views batched into one "Event Views" structured event on activity and when the page is
 * hidden, and link clicks on an event's ticket button.
 *
 * Settings come from window.evvntCalendarTracking (Evvnt_Calendar_Tracking::config()). This
 * script defines the "evvntSnowplow" command queue; Snowplow's tag (sp.js, loaded after it)
 * runs the queued commands. A separate namespace and tracker leave a site's own Snowplow
 * tracker alone.
 */
( function () {
	'use strict';

	var config = window.evvntCalendarTracking;
	if ( ! config || ! config.collector ) {
		return;
	}

	// The command queue. The tag claims it when it loads: the inline scripts around sp.js hand
	// it this namespace alone (Evvnt_Calendar_Tracking::enqueue()), so it never takes a
	// site's own Snowplow queue, nor a site's tag ours.
	var NAMESPACE = 'evvntSnowplow';
	if ( ! window[ NAMESPACE ] ) {
		window[ NAMESPACE ] = function () {
			( window[ NAMESPACE ].q = window[ NAMESPACE ].q || [] ).push( arguments );
		};
		window[ NAMESPACE ].q = window[ NAMESPACE ].q || [];
	}
	var sp = function () {
		window[ NAMESPACE ].apply( null, arguments );
	};

	// Commands go to every tracker in the namespace: just this one.
	sp( 'newTracker', config.trackerName, config.collector, config.tracker );
	sp( 'addGlobalContexts', [ config.environment ] );

	// discovery_plugin context for a calendar or a widget.
	var pluginContext = function ( type ) {
		var data = {};
		for ( var key in config.plugin.data ) {
			if ( Object.prototype.hasOwnProperty.call( config.plugin.data, key ) ) {
				data[ key ] = config.plugin.data[ key ];
			}
		}
		data.type = type;
		return { schema: config.plugin.schema, data: data };
	};

	var eventContext = function ( data ) {
		return {
			schema: 'iglu:com.evvnt/event/jsonschema/1-0-0',
			data: {
				source: data.source,
				sourceOccurrenceId: data.sourceOccurrenceId,
				sourceEventId: data.sourceEventId,
			},
		};
	};

	var parse = function ( json ) {
		try {
			return JSON.parse( json );
		} catch ( e ) {
			return null;
		}
	};

	// The outermost calendar or widget an element belongs to, and which of the two it is.
	var rootOf = function ( el ) {
		var root = null;
		for ( var node = el; node && node.nodeType === 1; node = node.parentNode ) {
			if ( node.classList.contains( 'evvnt-cal' ) ) {
				root = node;
			}
		}
		return root;
	};
	var typeOf = function ( root ) {
		return root && root.classList.contains( 'evvnt-cal--widget' ) ? 'widget' : 'calendar';
	};

	/* ---------- event views, batched per calendar / widget type ---------- */

	var pending = {};
	var queueView = function ( el ) {
		var data = parse( el.getAttribute( 'data-evvnt-view' ) );
		if ( ! data ) {
			return;
		}
		var type = typeOf( rootOf( el ) );
		( pending[ type ] = pending[ type ] || [] ).push( {
			schema: 'iglu:com.evvnt/event_view/jsonschema/2-0-0',
			data: {
				viewedFrom: data.viewedFrom,
				source: data.source,
				sourceOccurrenceId: data.sourceOccurrenceId,
				sourceEventId: data.sourceEventId,
			},
		} );
	};
	var pushPendingViews = function () {
		for ( var type in pending ) {
			if ( Object.prototype.hasOwnProperty.call( pending, type ) && pending[ type ].length ) {
				var contexts = pending[ type ].splice( 0, pending[ type ].length );
				contexts.push( pluginContext( type ) );
				sp( 'trackStructEvent', { category: 'Event Views', action: 'group_event_view', context: contexts } );
			}
		}
	};

	// Sends the queued views on activity (as page pings would), from the first page view on.
	sp( 'enableActivityTrackingCallback', { minimumVisitLength: 1, heartbeatDelay: 3, callback: pushPendingViews } );
	document.addEventListener( 'visibilitychange', function () {
		if ( document.visibilityState === 'hidden' ) {
			pushPendingViews();
		}
	} );

	/* ---------- page views, clicks, and what to watch ---------- */

	var viewedTypes = {};
	var trackPageView = function ( root ) {
		var type = typeOf( root );
		if ( viewedTypes[ type ] ) {
			return;
		}
		viewedTypes[ type ] = true;
		// A page view starts a new page view id: send views queued under the current one first.
		pushPendingViews();
		sp( 'trackPageView', { context: [ pluginContext( type ) ] } );
	};

	// Once each, when it first comes into view (or straight away without IntersectionObserver).
	var whenSeen = function ( elements, callback ) {
		if ( ! elements.length ) {
			return;
		}
		if ( ! ( 'IntersectionObserver' in window ) ) {
			elements.forEach( callback );
			return;
		}
		var observer = new IntersectionObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
				if ( entry.isIntersecting ) {
					observer.unobserve( entry.target );
					callback( entry.target );
				}
			} );
		} );
		elements.forEach( function ( el ) {
			observer.observe( el );
		} );
	};

	document.addEventListener( 'click', function ( e ) {
		var link = e.target && e.target.closest ? e.target.closest( '[data-evvnt-click]' ) : null;
		var data = link ? parse( link.getAttribute( 'data-evvnt-click' ) ) : null;
		if ( ! data || ! data.event ) {
			return;
		}
		sp(
			'trackLinkClick',
			{
				targetUrl: data.targetUrl || link.href,
				elementId: data.elementId,
				elementClasses: Array.prototype.slice.call( link.classList ),
				elementTarget: link.target,
				elementContent: ( link.textContent || '' ).replace( /\s+/g, ' ' ).trim(),
				context: [ eventContext( data.event ), pluginContext( typeOf( rootOf( link ) ) ) ],
			}
		);
	} );

	var init = function () {
		var all = function ( selector ) {
			return Array.prototype.slice.call( document.querySelectorAll( selector ) );
		};
		whenSeen(
			all( '.evvnt-cal' ).filter( function ( el ) {
				return rootOf( el ) === el;
			} ),
			trackPageView
		);
		var views = all( '[data-evvnt-view]' );
		// The event on an event page counts as viewed on load; cards and tiles when seen.
		views.forEach( function ( el ) {
			var data = parse( el.getAttribute( 'data-evvnt-view' ) );
			if ( data && data.viewedFrom === 'event_page' ) {
				queueView( el );
			}
		} );
		whenSeen(
			views.filter( function ( el ) {
				var data = parse( el.getAttribute( 'data-evvnt-view' ) );
				return data && data.viewedFrom !== 'event_page';
			} ),
			queueView
		);
	};

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
