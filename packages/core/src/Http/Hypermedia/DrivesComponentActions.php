<?php

declare(strict_types=1);

namespace Preflow\Core\Http\Hypermedia;

/**
 * A hypermedia client that runs component actions the 0.86 way: it writes the attributes of an
 * element that calls an action from neutral options (ActionCall, ActionOptions), carries a
 * component's state with each of its actions, and brings the new state back after one.
 *
 * Optional: a driver without it still serves the old-style calls (hd.post('x', class, id, props)).
 * HtmxDriver implements it.
 *
 * The request fields are the same for every driver, so the component endpoint reads them without
 * knowing the client:
 *
 * - STATE_FIELD[<componentId>]: the state string the page carries for that component;
 * - VALUES_FIELD: the values the call sends, as one JSON object (a string), or as fields
 *   VALUES_FIELD[<name>] (unsigned: the action gets them as input, beside the fields of the form
 *   the element sits in, which win over them);
 * - REGION_FIELD: the region of the component the answer renders; empty or missing for the
 *   whole component.
 *
 * While a request runs, the element that sent it carries the class BUSY_CLASS, so CSS for
 * "loading" names no client library.
 *
 * @since 0.86.0
 */
interface DrivesComponentActions
{
    /** The request field holding state strings, keyed by component id. */
    public const string STATE_FIELD = '_state';

    /** The request field holding the values a call sends, keyed by name. */
    public const string VALUES_FIELD = '_values';

    /** The request field naming the region the answer renders. */
    public const string REGION_FIELD = '_region';

    /** The class an element carries while a request it sent runs. */
    public const string BUSY_CLASS = 'preflow-busy';

    /**
     * The attributes (name => value, unescaped) of an element that runs $call: its method and
     * URL, its target and swap, its trigger, its values in VALUES_FIELD, the target region (if
     * any) in REGION_FIELD, and what makes it send the state its component carries. One request
     * per component at a time: a second one waits for the first, or, with `latest`, replaces it.
     *
     * @return array<string, string>
     */
    public function callAttributes(ActionCall $call): array;

    /**
     * The attributes of an element that refreshes $call's component when $event happens anywhere
     * on the page (an event an action's answer fires): callAttributes() with $event as the only
     * trigger. $call is the component's `render` call; its state goes with it, so the refresh
     * renders the component as the page has it (no mount() again).
     *
     * @throws \InvalidArgumentException an event name with characters other than letters, digits,
     *         `-`, `_`, `.` and `:`, or a call with events of its own (ActionOptions `on`)
     * @return array<string, string>
     */
    public function listenAttributes(ActionCall $call, string $event): array;

    /**
     * The markup that carries $carried (a component's state string) on the page; the component
     * renderer puts it first inside the component's wrapper. It sends STATE_FIELD[$componentId]
     * with every action of that component (callAttributes()), and is part of no form of the page.
     */
    public function stateCarrier(string $componentId, string $carried): string;

    /**
     * The markup appended to an action's answer so the page's carrier of $componentId holds
     * $carried afterwards, also when the answer replaced only a region inside the component.
     */
    public function stateUpdate(string $componentId, string $carried): string;
}
