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
 * Backup plugin for dictation questions.
 *
 * @package    qtype_dictation
 * @copyright  2024 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Provides the information to backup dictation questions.
 */
class backup_qtype_dictation_plugin extends backup_qtype_plugin {

    /**
     * Returns the qtype information to attach to question element.
     */
    protected function define_question_plugin_structure() {
        
        // Define the virtual plugin element with the condition to fulfill.
        $plugin = $this->get_plugin_element(null, '../../qtype', 'dictation');

        // Create one standard named plugin element (the visible container).
        $pluginwrapper = new backup_nested_element($this->get_recommended_name());

        // Connect the visible container ASAP.
        $plugin->add_child($pluginwrapper);

        // Define the question options structure.
        $options = new backup_nested_element('dictation_options', array('id'), array(
            'transcript', 'maxplays', 'enableaudio', 'gaps'
        ));

        // Define the attempts structure for research data.
        $attempts = new backup_nested_element('dictation_attempts');
        $attempt = new backup_nested_element('dictation_attempt', array('id'), array(
            'userid', 'attemptid', 'responses', 'scores', 'totalscore', 
            'playcount', 'timecreated', 'timemodified'
        ));

        // Build the tree.
        $pluginwrapper->add_child($options);
        $pluginwrapper->add_child($attempts);
        $attempts->add_child($attempt);

        // Define sources.
        $options->set_source_table('qtype_dictation_options', array('questionid' => backup::VAR_PARENTID));
        $attempt->set_source_table('qtype_dictation_attempts', array('questionid' => backup::VAR_PARENTID));

        // Define file annotations.
        //$options->annotate_files('qtype_dictation', 'audio', 'questionid');

        // Return the root element (pluginwrapper).
        return $plugin;
    }
}
