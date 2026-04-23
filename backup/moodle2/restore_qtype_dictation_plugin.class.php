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
 * Restore plugin for dictation questions.
 *
 * @package    qtype_dictation
 * @copyright  2024 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Restore plugin class that provides the necessary information needed to restore one dictation qtype plugin.
 */
class restore_qtype_dictation_plugin extends restore_qtype_plugin {

    /**
     * Returns the paths to be handled by the plugin at question level.
     */
    protected function define_question_plugin_structure() {
        
        $paths = array();

        // Add own qtype stuff.
        $elename = 'dictation_options';
        $elepath = $this->get_pathfor('/dictation_options');
        $paths[] = new restore_path_element($elename, $elepath);

        $elename = 'dictation_attempt';
        $elepath = $this->get_pathfor('/dictation_attempts/dictation_attempt');
        $paths[] = new restore_path_element($elename, $elepath);

        return $paths;
    }

    /**
     * Process the qtype/dictation_options element.
     */
    public function process_dictation_options($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        // Detect if the question is created or mapped.
        $oldquestionid = $this->get_old_parentid('question');
        $newquestionid = $this->get_new_parentid('question');

        // If the question has been created by restore, we need to create its options too.
        if ($this->get_mappingid('question_created', $oldquestionid)) {
            // Adjust some columns.
            $data->questionid = $newquestionid;
            
            // Insert record.
            $newitemid = $DB->insert_record('qtype_dictation_options', $data);
            
            // Create mapping (there are files and answers referencing it).
            $this->set_mapping('qtype_dictation_options', $oldid, $newitemid);
        }
    }

    /**
     * Process the qtype/dictation_attempts/dictation_attempt element.
     */
    public function process_dictation_attempt($data) {
        global $DB;

        $data = (object)$data;

        $oldquestionid = $this->get_old_parentid('question');
        $newquestionid = $this->get_new_parentid('question');

        // Adjust some columns.
        $data->questionid = $newquestionid;
        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->attemptid = $this->get_mappingid('question_attempt', $data->attemptid);

        // Only restore if we have valid mappings.
        if ($data->userid && $data->attemptid) {
            $DB->insert_record('qtype_dictation_attempts', $data);
        }
    }

    /**
     * Return the contents of this qtype to be processed by the links decoder.
     */
    public static function define_decode_contents() {
        $contents = array();

        $contents[] = new restore_decode_content('qtype_dictation_options', 'transcript', 'qtype_dictation_options');

        return $contents;
    }
}
