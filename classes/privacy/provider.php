<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Privacy Subsystem implementation for qtype_dictation.
 *
 * @package    qtype_dictation
 * @copyright  2025 Deepak Sharma <deepak@palinfocom.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace qtype_dictation\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

defined('MOODLE_INTERNAL') || die();

/**
 * Privacy Subsystem for qtype_dictation implementing metadata and request provider.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    /**
     * Returns meta data about this system.
     *
     * @param collection $collection The initialised collection to add items to.
     * @return collection A listing of user data stored through this system.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'qtype_dictation_attempts',
            [
                'questionid' => 'privacy:metadata:qtype_dictation_attempts:questionid',
                'userid' => 'privacy:metadata:qtype_dictation_attempts:userid',
                'attemptid' => 'privacy:metadata:qtype_dictation_attempts:attemptid',
                'responses' => 'privacy:metadata:qtype_dictation_attempts:responses',
                'scores' => 'privacy:metadata:qtype_dictation_attempts:scores',
                'totalscore' => 'privacy:metadata:qtype_dictation_attempts:totalscore',
                'playcount' => 'privacy:metadata:qtype_dictation_attempts:playcount',
                'timecreated' => 'privacy:metadata:qtype_dictation_attempts:timecreated',
                'timemodified' => 'privacy:metadata:qtype_dictation_attempts:timemodified',
            ],
            'privacy:metadata:qtype_dictation_attempts'
        );

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid The user to search.
     * @return contextlist The contextlist containing the list of contexts used in this plugin.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT DISTINCT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {quiz} q ON q.id = cm.instance
                  JOIN {quiz_attempts} qa ON qa.quiz = q.id
                  JOIN {question_usages} qu ON qu.id = qa.uniqueid
                  JOIN {question_attempts} qatt ON qatt.questionusageid = qu.id
                  JOIN {qtype_dictation_attempts} qda ON qda.attemptid = qatt.id
                 WHERE qda.userid = :userid";

        $params = [
            'contextlevel' => CONTEXT_MODULE,
            'modname' => 'quiz',
            'userid' => $userid,
        ];

        $contextlist->add_from_sql($sql, $params);

        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist containing the list of users who have data in this context/plugin combination.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();

        if (!$context instanceof \context_module) {
            return;
        }

        $sql = "SELECT qda.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {quiz} q ON q.id = cm.instance
                  JOIN {quiz_attempts} qa ON qa.quiz = q.id
                  JOIN {question_usages} qu ON qu.id = qa.uniqueid
                  JOIN {question_attempts} qatt ON qatt.questionusageid = qu.id
                  JOIN {qtype_dictation_attempts} qda ON qda.attemptid = qatt.id
                 WHERE cm.id = :cmid";

        $params = [
            'modname' => 'quiz',
            'cmid' => $context->instanceid,
        ];

        $userlist->add_from_sql('userid', $sql, $params);
    }

    /**
     * Export all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to export information for.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $user = $contextlist->get_user();

        list($contextsql, $contextparams) = $DB->get_in_or_equal($contextlist->get_contextids(), SQL_PARAMS_NAMED);

        $sql = "SELECT qda.*,
                       q.name as questionname,
                       ctx.id as contextid
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {quiz} qz ON qz.id = cm.instance
                  JOIN {quiz_attempts} qa ON qa.quiz = qz.id
                  JOIN {question_usages} qu ON qu.id = qa.uniqueid
                  JOIN {question_attempts} qatt ON qatt.questionusageid = qu.id
                  JOIN {question} q ON q.id = qatt.questionid
                  JOIN {qtype_dictation_attempts} qda ON qda.attemptid = qatt.id
                 WHERE ctx.id {$contextsql}
                   AND qda.userid = :userid
              ORDER BY qda.timecreated";

        $params = [
            'contextlevel' => CONTEXT_MODULE,
            'modname' => 'quiz',
            'userid' => $user->id,
        ] + $contextparams;

        $attempts = $DB->get_records_sql($sql, $params);

        foreach ($attempts as $attempt) {
            $context = \context::instance_by_id($attempt->contextid);
            
            $data = (object) [
                'questionname' => $attempt->questionname,
                'responses' => $attempt->responses,
                'scores' => $attempt->scores,
                'totalscore' => $attempt->totalscore,
                'playcount' => $attempt->playcount,
                'timecreated' => \core_privacy\local\request\transform::datetime($attempt->timecreated),
                'timemodified' => \core_privacy\local\request\transform::datetime($attempt->timemodified),
            ];

            writer::with_context($context)->export_data(
                [get_string('privacy:metadata:qtype_dictation_attempts', 'qtype_dictation'), $attempt->id],
                $data
            );
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param \context $context The specific context to delete data for.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        if (!$context instanceof \context_module) {
            return;
        }

        $sql = "SELECT qda.id
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {quiz} q ON q.id = cm.instance
                  JOIN {quiz_attempts} qa ON qa.quiz = q.id
                  JOIN {question_usages} qu ON qu.id = qa.uniqueid
                  JOIN {question_attempts} qatt ON qatt.questionusageid = qu.id
                  JOIN {qtype_dictation_attempts} qda ON qda.attemptid = qatt.id
                 WHERE cm.id = :cmid";

        $params = [
            'modname' => 'quiz',
            'cmid' => $context->instanceid,
        ];

        $attemptids = $DB->get_fieldset_sql($sql, $params);

        if (!empty($attemptids)) {
            list($insql, $inparams) = $DB->get_in_or_equal($attemptids, SQL_PARAMS_NAMED);
            $DB->delete_records_select('qtype_dictation_attempts', "id $insql", $inparams);
        }
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts and user information to delete information for.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $user = $contextlist->get_user();

        list($contextsql, $contextparams) = $DB->get_in_or_equal($contextlist->get_contextids(), SQL_PARAMS_NAMED);

        $sql = "SELECT qda.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {quiz} q ON q.id = cm.instance
                  JOIN {quiz_attempts} qa ON qa.quiz = q.id
                  JOIN {question_usages} qu ON qu.id = qa.uniqueid
                  JOIN {question_attempts} qatt ON qatt.questionusageid = qu.id
                  JOIN {qtype_dictation_attempts} qda ON qda.attemptid = qatt.id
                 WHERE ctx.id {$contextsql}
                   AND qda.userid = :userid";

        $params = [
            'contextlevel' => CONTEXT_MODULE,
            'modname' => 'quiz',
            'userid' => $user->id,
        ] + $contextparams;

        $attemptids = $DB->get_fieldset_sql($sql, $params);

        if (!empty($attemptids)) {
            list($insql, $inparams) = $DB->get_in_or_equal($attemptids, SQL_PARAMS_NAMED);
            $DB->delete_records_select('qtype_dictation_attempts', "id $insql", $inparams);
        }
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param approved_userlist $userlist The approved context and user information to delete information for.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();

        if (!$context instanceof \context_module) {
            return;
        }

        $userids = $userlist->get_userids();

        if (empty($userids)) {
            return;
        }

        list($usersql, $userparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        $sql = "SELECT qda.id
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {quiz} q ON q.id = cm.instance
                  JOIN {quiz_attempts} qa ON qa.quiz = q.id
                  JOIN {question_usages} qu ON qu.id = qa.uniqueid
                  JOIN {question_attempts} qatt ON qatt.questionusageid = qu.id
                  JOIN {qtype_dictation_attempts} qda ON qda.attemptid = qatt.id
                 WHERE cm.id = :cmid
                   AND qda.userid {$usersql}";

        $params = [
            'modname' => 'quiz',
            'cmid' => $context->instanceid,
        ] + $userparams;

        $attemptids = $DB->get_fieldset_sql($sql, $params);

        if (!empty($attemptids)) {
            list($insql, $inparams) = $DB->get_in_or_equal($attemptids, SQL_PARAMS_NAMED);
            $DB->delete_records_select('qtype_dictation_attempts', "id $insql", $inparams);
        }
    }
}
