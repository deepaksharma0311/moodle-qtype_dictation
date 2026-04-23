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
 * Dictation question type upgrade script.
 *
 * @package    qtype_dictation
 * @copyright  2024 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade script for dictation question type.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_qtype_dictation_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    // Add leftaligntext column if upgrading from older version
    if ($oldversion < 2024120902) {
        $table = new xmldb_table('qtype_dictation_options');
        $field = new xmldb_field('leftaligntext', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'scoringmethod');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Dictation savepoint reached.
        upgrade_plugin_savepoint(true, 2024120902, 'qtype', 'dictation');
    }

    return true;
}