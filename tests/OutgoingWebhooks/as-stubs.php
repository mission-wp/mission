<?php
/**
 * Global-namespace Action Scheduler stubs for webhook tests.
 *
 * The PHPUnit environment never loads Action Scheduler (see tests/bootstrap.php),
 * so these stubs record scheduling calls for assertions. as_enqueue_async_action
 * is intentionally NOT stubbed: dispatch code paths must keep using their
 * synchronous do_action() fallbacks (see ImportPipelineTest).
 *
 * @package MissionDP
 */

if ( ! function_exists( 'as_schedule_single_action' ) ) {
	/**
	 * Record a single-action scheduling call.
	 *
	 * @param int    $timestamp When the action should run.
	 * @param string $hook      Hook name.
	 * @param array  $args      Hook arguments.
	 * @param string $group     Action group.
	 *
	 * @return int Fake action ID.
	 */
	function as_schedule_single_action( $timestamp, $hook, $args = [], $group = '' ) {
		$GLOBALS['missiondp_as_stub_calls']['schedule_single'][] = compact( 'timestamp', 'hook', 'args', 'group' );

		return count( $GLOBALS['missiondp_as_stub_calls']['schedule_single'] );
	}
}

if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
	/**
	 * Record an unschedule-all call.
	 *
	 * @param string $hook  Hook name.
	 * @param array  $args  Hook arguments.
	 * @param string $group Action group.
	 *
	 * @return void
	 */
	function as_unschedule_all_actions( $hook = '', $args = [], $group = '' ) {
		$GLOBALS['missiondp_as_stub_calls']['unschedule_all'][] = compact( 'hook', 'args', 'group' );
	}
}
