<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Shared;

/**
 * The contextual permissions of the bundle (contract section 13). Deny by default: the host's checker answers each
 * one per tenant (and per worker for the "manage" ones); the default role map grants nothing until roles are mapped.
 */
enum WorkingTimePermission: string
{
    /** Clock in / out, own days and months, own corrections, own monthly copy. */
    case Clock = 'clock';
    /** Team register, decide and record corrections, schedules (subject: the managed worker). */
    case ManageTeam = 'manage_team';
    /** Inspection export of a worker or the team. */
    case Export = 'export';
    /** Register (and leave) settings of the tenant. */
    case ManageSettings = 'manage_settings';
    /** Own leave (S3). */
    case LeaveRequest = 'leave_request';
    /** Decide leave (S3). */
    case LeaveApprove = 'leave_approve';
    /** Team leave calendar (S3). */
    case LeaveViewTeam = 'leave_view_team';
    /** Leave administration (S3). */
    case LeaveManage = 'leave_manage';
    /** Holiday calendars (S2). */
    case HolidaysManage = 'holidays_manage';
}
