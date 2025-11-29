(function ( $, window ) {
	'use strict';

	var state = {
		job: null,
		timer: null,
	};

	var MAX_LIST_ITEMS = 50;
	var isArray = Array.isArray || $.isArray;

	$( function () {
		if ( typeof window.kigScan === 'undefined' ) {
			return;
		}

		var settings = window.kigScan;
		var form = $( '#wpig-scan-form' );

		if ( ! form.length ) {
			return;
		}

		var startButton = $( '#wpig-start-scan' );
		var progressBar = $( '#wpig-scan-progress' );
		var statusText = $( '#wpig-scan-status-text' );
		var messageArea = $( '#wpig-scan-message' );
		var summaryArea = $( '#wpig-scan-summary' );
		var lastSummaryArea = $( '#wpig-last-scan' );

		resetProgress();
		renderPreviousSummary( settings.lastScan );

		form.on( 'submit', function ( event ) {
			event.preventDefault();
			startScan();
		} );

		function startScan() {
			window.clearTimeout( state.timer );
			state.job = null;

			setBusyState( true );
			updateProgress( 0, settings.text.progressPreparing || settings.text.progressIdle );
			messageArea.removeClass( 'notice notice-error' ).empty();
			clearSummary();

			var payload = form.serializeArray();

			payload.push( { name: 'action', value: 'kig_start_scan' } );
			payload.push( { name: 'nonce', value: settings.nonces.start } );

			$.post( settings.ajaxUrl, payload )
				.done( function ( response ) {
					if ( ! response || ! response.success || ! response.data || ! response.data.job ) {
						return handleError();
					}

					state.job = response.data.job;
					pollStatus();
				} )
				.fail( handleError );
		}

		function pollStatus() {
			if ( ! state.job ) {
				return handleError();
			}

			$.post( settings.ajaxUrl, {
				action: 'kig_scan_status',
				nonce: settings.nonces.status,
				job: state.job,
			} )
				.done( function ( response ) {
					if ( ! response || ! response.success || ! response.data ) {
						return handleError();
					}

					var data = response.data;
					updateProgress( data.progress || 0, data.current_item || '' );

					if ( data.completed ) {
						setBusyState( false );
						state.job = null;
						renderSummary( data.results || {}, data.summary || '' );

						if ( data.summary ) {
							settings.lastScan = {
								summary: data.summary,
								completedAt: data.completedAt || 0,
							};
							renderPreviousSummary( settings.lastScan );
						}

						return;
					}

					state.timer = window.setTimeout( pollStatus, 1500 );
				} )
				.fail( handleError );
		}

		function handleError() {
			window.clearTimeout( state.timer );
			state.timer = null;
			state.job = null;

			setBusyState( false );
			updateProgress( 0, settings.text.progressIdle );
			messageArea
				.addClass( 'notice notice-error' )
				.text( settings.text.errorMessage );
		}

		function setBusyState( isBusy ) {
			startButton.prop( 'disabled', isBusy );
			if ( isBusy ) {
				startButton.text( settings.text.buttonWorking || 'Starting…' );
			} else {
				startButton.text( settings.text.buttonStart || 'Start Scan' );
			}
		}

		function updateProgress( value, message ) {
			var normalized = Math.max( 0, Math.min( parseInt( value, 10 ) || 0, 100 ) );
			progressBar.val( normalized );
			statusText.text( message || settings.text.progressIdle );

			if ( normalized >= 100 ) {
				statusText.text( settings.text.progressCompleted );
			}
		}

		function resetProgress() {
			updateProgress( 0, settings.text.progressIdle );
			setBusyState( false );
			messageArea.removeClass( 'notice notice-error' ).empty();
		}

	function clearSummary() {
		if ( summaryArea.length ) {
			summaryArea.removeClass( 'notice notice-success notice-warning notice-info notice-error' ).empty().hide();
		}
	}

	function renderSummary( results, summaryText ) {
		if ( ! summaryArea.length ) {
			return;
		}

		clearSummary();

		if ( ! summaryText ) {
			return;
		}

		summaryArea.show();

		var core = results && results.core ? results.core : null;
		var plugins = results && results.plugins ? results.plugins : null;
		var themes = results && results.themes ? results.themes : null;
		var hasIssues = false;
		var hasErrors = false;

		if ( core ) {
			if ( isArray( core.modified ) && core.modified.length ) {
				hasIssues = true;
			}
			if ( isArray( core.missing ) && core.missing.length ) {
				hasIssues = true;
			}
			if ( isArray( core.added ) && core.added.length ) {
				hasIssues = true;
			}
			if ( isArray( core.errors ) && core.errors.length ) {
				hasErrors = true;
			}
		}

		if ( plugins ) {
			if ( isArray( plugins.items ) ) {
				plugins.items.forEach( function ( item ) {
					if ( item && item.status === 'issues' ) {
						hasIssues = true;
					}
					if ( item && ( item.status === 'error' || ( isArray( item.errors ) && item.errors.length ) ) ) {
						hasErrors = true;
					}
				} );
			}
			if ( isArray( plugins.errors ) && plugins.errors.length ) {
				hasErrors = true;
			}
		}

		if ( themes ) {
			if ( isArray( themes.items ) ) {
				themes.items.forEach( function ( item ) {
					if ( item && item.status === 'issues' ) {
						hasIssues = true;
					}
					if ( item && ( item.status === 'error' || ( isArray( item.errors ) && item.errors.length ) ) ) {
						hasErrors = true;
					}
				} );
			}
			if ( isArray( themes.errors ) && themes.errors.length ) {
				hasErrors = true;
			}
		}

		var noticeClass = 'notice notice-success';
		if ( hasErrors ) {
			noticeClass = 'notice notice-error';
		} else if ( hasIssues ) {
			noticeClass = 'notice notice-warning';
		}

		summaryArea
			.addClass( noticeClass )
			.append( $( '<p />' ).text( summaryText ) );

		if ( core ) {
			renderCoreSection( core );
		}

		if ( plugins ) {
			renderTargetSection( settings.text.pluginsHeading || 'Plugins', plugins );
		}

		if ( themes ) {
			renderTargetSection( settings.text.themesHeading || 'Themes', themes );
		}

		if ( ! hasIssues && ! hasErrors ) {
			summaryArea.append( $( '<p />' ).text( settings.text.noIssues ) );
		}
	}

	function appendGroup( labelKey, items, container ) {
		if ( ! items || ! items.length ) {
			return false;
		}

		var target = container || summaryArea;
		var label = settings.text[ labelKey ] ? settings.text[ labelKey ].replace( '%d', items.length ) : '';
		var wrapper = $( '<div class="wpig-summary-group" />' );

		if ( label ) {
			wrapper.append( $( '<p />' ).append( $( '<strong />' ).text( label ) ) );
		}

		var list = $( '<ul class="wpig-summary-list" />' );

		items.slice( 0, MAX_LIST_ITEMS ).forEach( function ( item ) {
			var file = extractFile( item );
			if ( ! file ) {
				return;
			}

			var entry = $( '<li />' ).text( file );

			if ( $.isPlainObject( item ) && item.actual && item.expected && item.actual !== item.expected ) {
				entry.append( $( '<span class="wpig-summary-hash" />' ).text( ' (' + item.actual + ')' ) );
			}

			list.append( entry );
		} );

		if ( ! list.children().length ) {
			return false;
		}

		if ( items.length > MAX_LIST_ITEMS ) {
			list.append( $( '<li />' ).text( '…' ) );
		}

		wrapper.append( list );
		target.append( wrapper );

		return true;
	}

	function renderCoreSection( core ) {
		var container = $( '<div class="wpig-summary-section" />' );
		var heading = settings.text.coreHeading || 'WordPress Core';
		container.append( $( '<h3 class="wpig-summary-heading" />' ).text( heading ) );

		var modified = isArray( core.modified ) ? core.modified : [];
		var missing = isArray( core.missing ) ? core.missing : [];
		var added = isArray( core.added ) ? core.added : [];
		var errors = isArray( core.errors ) ? core.errors : [];
		var hasDetails = false;

		hasDetails = appendGroup( 'modifiedTitle', modified, container ) || hasDetails;
		hasDetails = appendGroup( 'missingTitle', missing, container ) || hasDetails;
		hasDetails = appendGroup( 'addedTitle', added, container ) || hasDetails;

		if ( errors.length ) {
			var label = settings.text.errorsLabel ? settings.text.errorsLabel.replace( '%d', errors.length ) : '';
			hasDetails = renderErrorList( errors, label, container ) || hasDetails;
		}

		if ( ! hasDetails ) {
			container.append( $( '<p />' ).text( settings.text.noDifferences || settings.text.noIssues ) );
		}

		summaryArea.append( container );
	}

	function renderTargetSection( heading, data ) {
		var container = $( '<div class="wpig-summary-section" />' );
		container.append( $( '<h3 class="wpig-summary-heading" />' ).text( heading || '' ) );

		var items = isArray( data.items ) ? data.items : [];
		var hasContent = false;

		items.forEach( function ( item ) {
			var block = $( '<div class="wpig-summary-extension-item" />' );
			var titleParts = [];

			if ( item && item.name ) {
				titleParts.push( item.name );
			}

			if ( item && item.version && settings.text.versionLabel ) {
				titleParts.push( settings.text.versionLabel.replace( '%s', item.version ) );
			}

			var statusText = '';
			if ( item && item.status === 'issues' ) {
				statusText = settings.text.issuesDetected || '';
			} else if ( item && item.status === 'error' ) {
				statusText = item.message || settings.text.statusError || '';
			} else if ( item ) {
				statusText = item.message || settings.text.noDifferences || '';
			}

			var headingLine = titleParts.join( ' · ' );
			if ( statusText ) {
				headingLine = headingLine ? headingLine + ' — ' + statusText : statusText;
			}

			if ( headingLine ) {
				block.append( $( '<p />' ).append( $( '<strong />' ).text( headingLine ) ) );
			}

			var itemHasDetails = false;
			itemHasDetails = appendGroup( 'modifiedTitle', item && item.modified, block ) || itemHasDetails;
			itemHasDetails = appendGroup( 'missingTitle', item && item.missing, block ) || itemHasDetails;
			itemHasDetails = appendGroup( 'addedTitle', item && item.added, block ) || itemHasDetails;

			if ( item && isArray( item.errors ) && item.errors.length ) {
				var label = settings.text.errorsLabel ? settings.text.errorsLabel.replace( '%d', item.errors.length ) : '';
				renderErrorList( item.errors, label, block );
				itemHasDetails = true;
			}

			if ( ! itemHasDetails && ! headingLine ) {
				block.append( $( '<p />' ).text( settings.text.noDifferences || settings.text.noIssues ) );
			}

			container.append( block );
			hasContent = true;
		} );

		if ( isArray( data.skipped ) && data.skipped.length ) {
			var skippedLabel = settings.text.skippedLabel ? settings.text.skippedLabel.replace( '%d', data.skipped.length ) : '';
			var skippedWrapper = $( '<div class="wpig-summary-group" />' );

			if ( skippedLabel ) {
				skippedWrapper.append( $( '<p />' ).append( $( '<strong />' ).text( skippedLabel ) ) );
			}

			var skippedList = $( '<ul class="wpig-summary-list" />' );

			data.skipped.slice( 0, MAX_LIST_ITEMS ).forEach( function ( entry ) {
				if ( ! entry ) {
					return;
				}

				var text = entry.name || entry.key || '';
				if ( entry.reason ) {
					text = text ? text + ' — ' + entry.reason : entry.reason;
				}

				if ( text ) {
					skippedList.append( $( '<li />' ).text( text ) );
				}
			} );

			if ( data.skipped.length > MAX_LIST_ITEMS ) {
				skippedList.append( $( '<li />' ).text( '…' ) );
			}

			if ( skippedList.children().length ) {
				skippedWrapper.append( skippedList );
				container.append( skippedWrapper );
				hasContent = true;
			}
		}

		if ( isArray( data.errors ) && data.errors.length ) {
			var errorsLabel = settings.text.errorsLabel ? settings.text.errorsLabel.replace( '%d', data.errors.length ) : '';
			hasContent = renderErrorList( data.errors, errorsLabel, container ) || hasContent;
		}

		if ( ! hasContent ) {
			container.append( $( '<p />' ).text( settings.text.noDifferences || settings.text.noIssues ) );
		}

		summaryArea.append( container );
	}

	function renderErrorList( entries, label, container ) {
		if ( ! isArray( entries ) || ! entries.length ) {
			return false;
		}

		var wrapper = $( '<div class="wpig-summary-group" />' );
		if ( label ) {
			wrapper.append( $( '<p />' ).append( $( '<strong />' ).text( label ) ) );
		}

		var list = $( '<ul class="wpig-summary-list" />' );

		entries.slice( 0, MAX_LIST_ITEMS ).forEach( function ( entry ) {
			if ( ! entry ) {
				return;
			}

			var text = '';
			if ( entry.file ) {
				text = entry.file;
			} else if ( entry.name ) {
				text = entry.name;
			} else if ( entry.key ) {
				text = entry.key;
			}

			if ( entry.message ) {
				text = text ? text + ' — ' + entry.message : entry.message;
			}

			if ( text ) {
				list.append( $( '<li />' ).text( text ) );
			}
		} );

		if ( entries.length > MAX_LIST_ITEMS ) {
			list.append( $( '<li />' ).text( '…' ) );
		}

		if ( ! list.children().length ) {
			return false;
		}

		wrapper.append( list );
		( container || summaryArea ).append( wrapper );

		return true;
	}

		function extractFile( item ) {
			if ( $.isPlainObject( item ) ) {
				return item.file || '';
			}

			return item;
		}

		function renderPreviousSummary( data ) {
			if ( ! lastSummaryArea.length ) {
				return;
			}

			lastSummaryArea.removeClass( 'notice notice-info' ).empty();

			if ( ! data || ! data.summary ) {
				lastSummaryArea.hide();
				return;
			}

			lastSummaryArea
				.show()
				.addClass( 'notice notice-info' )
				.append( $( '<p />' ).text( settings.text.viewLastSummary + ' ' + data.summary ) );

			var formatted = formatTimestamp( data.completedAt );

			if ( formatted ) {
				var label = settings.text.lastRunLabel ? settings.text.lastRunLabel.replace( '%s', formatted ) : formatted;
				lastSummaryArea.append( $( '<p />' ).text( label ) );
			}
		}

		function formatTimestamp( ts ) {
			if ( ! ts ) {
				return '';
			}

			var date = new Date( ts * 1000 );

			if ( isNaN( date.getTime() ) ) {
				return '';
			}

			return date.toLocaleString();
		}
	} );
}( jQuery, window ));
