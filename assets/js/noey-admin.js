/**
 * NoeyAPI Admin JavaScript
 * Handles: Test Suite AJAX, Debug Log interactions, auto-refresh
 */
/* global NoeyAdmin, jQuery */
( function ( $ ) {
    'use strict';

    // ── Debug Log ─────────────────────────────────────────────────────────────

    // Toggle data rows on click
    $( document ).on( 'click', '[data-target]', function () {
        const targetId = $( this ).data( 'target' );
        $( '#' + targetId ).toggle();
    } );

    // Clear logs
    $( '#noey-clear-logs' ).on( 'click', function () {
        if ( ! confirm( 'Delete all debug log entries? This cannot be undone.' ) ) return;

        const $btn = $( this ).prop( 'disabled', true ).text( 'Clearing…' );

        $.post( NoeyAdmin.ajaxUrl, {
            action : 'noey_clear_logs',
            nonce  : NoeyAdmin.nonce,
        }, function ( res ) {
            if ( res.success ) {
                location.reload();
            } else {
                alert( 'Failed to clear logs.' );
                $btn.prop( 'disabled', false ).text( 'Clear All Logs' );
            }
        } );
    } );

    // Auto-refresh
    let autoRefreshTimer = null;

    $( '#noey-autorefresh' ).on( 'change', function () {
        if ( this.checked ) {
            autoRefreshTimer = setInterval( () => location.reload(), 10000 );
        } else {
            clearInterval( autoRefreshTimer );
        }
    } );

    // ── Test Suite ────────────────────────────────────────────────────────────

    const testData = {};   // Shared data across tests (e.g. token from login)

    // Run single test
    $( document ).on( 'click', '.noey-run-test', function ( e ) {
        e.stopPropagation();
        const testId = $( this ).data( 'test' );
        runTest( testId );
    } );

    // Run all tests in a group
    $( document ).on( 'click', '.noey-run-group', function ( e ) {
        e.stopPropagation();
        const groupId = $( this ).data( 'group' );
        const tests   = $( '#group-' + groupId + ' .noey-run-test' );
        runSequential( tests.map( ( i, el ) => $( el ).data( 'test' ) ).get() );
    } );

    // Run all tests
    $( '#noey-run-all' ).on( 'click', function () {
        const tests = $( '.noey-run-test' ).map( ( i, el ) => $( el ).data( 'test' ) ).get();
        runSequential( tests );
    } );

    // Clear results
    $( '#noey-clear-results' ).on( 'click', function () {
        $( '.noey-test-status' ).text( '○' ).css( 'color', '' );
        $( '.noey-test-result' ).hide().removeClass( 'pass fail warn' ).html( '' );
        $( '#noey-test-summary' ).text( '' );
        Object.keys( testData ).forEach( k => delete testData[k] );
    } );

    function runSequential( ids ) {
        let chain = Promise.resolve();
        ids.forEach( id => { chain = chain.then( () => runTest( id ) ); } );
        chain.then( updateSummary );
    }

    function runTest( testId ) {
        const $status = $( '#status-' + testId );
        const $result = $( '#result-' + testId );

        $status.text( '⟳' ).css( 'color', '#aaa' );
        $result.hide().html( '' );

        return new Promise( resolve => {
            $.post( NoeyAdmin.ajaxUrl, {
                action : 'noey_test',
                nonce  : NoeyAdmin.nonce,
                test   : testId,
                data   : JSON.stringify( testData ),
            }, function ( res ) {
                if ( ! res ) { resolve(); return; }

                // Carry forward token from login
                if ( testId === 'auth_login' && res.pass && res.data && res.data.token ) {
                    testData.token   = res.data.token;
                    testData.user_id = res.data.user_id;
                }
                // Carry forward child_id from create
                if ( testId === 'children_create' && res.pass && res.data && res.data.child_id ) {
                    testData.child_id = res.data.child_id;
                }
                // Carry forward session_id from exam start
                if ( testId === 'exams_start' && res.pass && res.data && res.data.session_id ) {
                    testData.session_id = res.data.session_id;
                }

                renderResult( testId, res );
                resolve( res );
            } ).fail( () => {
                renderResult( testId, { pass: false, status: 'fail', message: 'AJAX request failed.', duration_ms: 0 } );
                resolve();
            } );
        } );
    }

    function renderResult( testId, res ) {
        const $status = $( '#status-' + testId );
        const $result = $( '#result-' + testId );

        const icon  = res.pass === true ? '✓' : ( res.pass === null ? '⚠' : '✗' );
        const color = res.pass === true ? '#16a34a' : ( res.pass === null ? '#d97706' : '#dc2626' );

        $status.text( icon ).css( 'color', color );

        const cls      = res.pass === true ? 'pass' : ( res.pass === null ? 'warn' : 'fail' );
        const dataHtml = res.data && Object.keys( res.data ).length
            ? '<pre class="noey-json noey-result-data">' + escHtml( JSON.stringify( res.data, null, 2 ) ) + '</pre>'
            : '';

        $result
            .removeClass( 'pass fail warn' )
            .addClass( cls )
            .html(
                '<div class="noey-result-message">' + escHtml( res.message || '' ) + '</div>'
                + dataHtml
                + '<div class="noey-duration">' + ( res.duration_ms || 0 ) + 'ms</div>'
            )
            .show();
    }

    function updateSummary() {
        const pass  = $( '.noey-test-status:contains(✓)' ).length;
        const fail  = $( '.noey-test-status:contains(✗)' ).length;
        const warn  = $( '.noey-test-status:contains(⚠)' ).length;
        const total = pass + fail + warn;

        $( '#noey-test-summary' ).html(
            '<span style="color:#16a34a">✓ ' + pass + ' passed</span>  '
            + '<span style="color:#dc2626">✗ ' + fail + ' failed</span>  '
            + '<span style="color:#d97706">⚠ ' + warn + ' warnings</span>'
            + '  <span style="color:#666">/ ' + total + ' total</span>'
        );
    }

    function escHtml( str ) {
        return String( str )
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' );
    }

} )( jQuery );
