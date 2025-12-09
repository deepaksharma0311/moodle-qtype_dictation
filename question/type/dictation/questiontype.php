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
 * Question type class for the dictation question type.
 *
 * @package    qtype_dictation
 * @copyright  2025 Deepak Sharma <deepak@palinfocom.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/engine/lib.php');
require_once($CFG->dirroot . '/question/type/dictation/question.php');

/**
 * The dictation question type.
 */
class qtype_dictation extends question_type {

    /**
     * Return an array of the question type's extra tables.
     *
     * @return array
     */
    public function extra_question_fields() {
        return array('qtype_dictation_options',
            'transcript',
            'maxplays',
            'enableaudio',
            'displaymode',
            'scoringmethod',
            'gaps',
            'leftaligntext'
        );
    }

    /**
     * Move any files belonging to this question from one context to another.
     *
     * @param int $questionid the question being moved.
     * @param int $oldcontextid the context it is moving from.
     * @param int $newcontextid the context it is moving to.
     */
    public function move_files($questionid, $oldcontextid, $newcontextid) {
        parent::move_files($questionid, $oldcontextid, $newcontextid);
        $this->move_files_in_hints($questionid, $oldcontextid, $newcontextid);
        
        $fs = get_file_storage();
        $fs->move_area_files_to_new_context($oldcontextid, $newcontextid,
            'qtype_dictation', 'audio', $questionid);
    }

    /**
     * Delete any files belonging to this question.
     *
     * @param int $questionid the question being deleted.
     * @param int $contextid the context the question is in.
     */
    protected function delete_files($questionid, $contextid) {
        parent::delete_files($questionid, $contextid);
        $this->delete_files_in_hints($questionid, $contextid);
        
        $fs = get_file_storage();
        $fs->delete_area_files($contextid, 'qtype_dictation', 'audio', $questionid);
    }

    /**
     * Saves question-type specific options.
     *
     * @param object $question This holds the information from the editing form.
     * @return object $result->error or $result->notice
     */
    public function save_question_options($question) {
        global $DB;
        
        $context = $question->context;
        $result = new stdClass();
       //  print_r($question);
       // exit();
        // Save options to database
        $options = $DB->get_record('qtype_dictation_options', array('questionid' => $question->id));
        if (!$options) {
            $options = new stdClass();
            $options->questionid = $question->id;
        }

        $options->transcript = $question->transcript;
        $options->maxplays = $question->maxplays;
        $options->enableaudio = isset($question->enableaudio) ? 1 : 0;
        $options->displaymode = isset($question->displaymode) ? $question->displaymode : 'standard';
        $options->scoringmethod = isset($question->scoringmethod) ? $question->scoringmethod : 'levenshtein';
        $options->leftaligntext = isset($question->leftaligntext) ? 1 : 0;
        $options->gaps = $this->extract_gaps($question->transcript);

        if (!empty($options->id)) {
            $DB->update_record('qtype_dictation_options', $options);
        } else {
            $DB->insert_record('qtype_dictation_options', $options);
        }

        // Save audio file
        if ($options->enableaudio) {
            $this->save_audio_file($question);
        }

        return $result;
    }

    /**
     * Extract gaps from transcript text with square bracket notation.
     *
     * @param string $transcript The transcript text
     * @return string JSON encoded array of gaps
     */
    private function extract_gaps($transcript) {
        $gaps = array();
        preg_match_all('/\[([^\]]+)\]/', $transcript, $matches);
        
        // foreach ($matches[1] as $gap) {
        //     $gaps[] = trim($gap);
        // }
        foreach ($matches[1] as $gap) {
            // Check if gap contains multiple comma-separated answers
            if (strpos($gap, ',') !== false) {
                // Split by comma and trim each answer
                $alternatives = array_map('trim', explode(',', $gap));
                $gaps[] = $alternatives;
            } else {
                // Single answer - still store as array for consistency
                $gaps[] = array(trim($gap));
            }
        }
        
        return json_encode($gaps);
    }

    /**
     * Save audio file to Moodle file system.
     *
     * @param object $question The question object
     */
    private function save_audio_file($question) {
        global $DB;
        
        $context = $question->context;
        
        // Get the draft file
        $draftitemid = $question->audiofile;
        if (!empty($draftitemid)) {
            file_save_draft_area_files($draftitemid, $context->id, 'qtype_dictation', 'audio',
                $question->id, array('subdirs' => false, 'maxfiles' => 1));
        }
    }

    /**
     * Initialise the common question_definition fields.
     *
     * @param question_definition $question the question_definition we are creating.
     * @param object $questiondata the question data loaded from the database.
     */
    protected function initialise_question_instance(question_definition $question, $questiondata) {
        parent::initialise_question_instance($question, $questiondata);
        
        $question->transcript = $questiondata->options->transcript;
        $question->maxplays = $questiondata->options->maxplays;
        $question->enableaudio = $questiondata->options->enableaudio;
        $question->displaymode = isset($questiondata->options->displaymode) ? $questiondata->options->displaymode : 'standard';
        $question->leftaligntext = isset($questiondata->options->leftaligntext) ? $questiondata->options->leftaligntext : 0;
        $question->scoringmethod = isset($questiondata->options->scoringmethod) ? $questiondata->options->scoringmethod : 'levenshtein';
        $question->gaps = json_decode($questiondata->options->gaps, true);
      
        // Load audio file information
        if ($question->enableaudio) {
            $question->audiofile = $this->get_audio_file_url($questiondata->id, $questiondata->contextid);
        } else {
            $question->audiofile = null;
        }
    }

    /**
     * Get the URL for the audio file.
     *
     * @param int $questionid The question ID
     * @param int $contextid The context ID
     * @return string|null The URL or null if no file
     */
    private function get_audio_file_url($questionid, $contextid) {
        $fs = get_file_storage();
        $files = $fs->get_area_files($contextid, 'qtype_dictation', 'audio', $questionid, 'id', false);
        
        if (!empty($files)) {
            $file = reset($files);
            return moodle_url::make_pluginfile_url(
                $file->get_contextid(),
                'qtype_dictation',
                'audio',
                $questionid,
                $file->get_filepath(),
                $file->get_filename()
            );
        }
        
        return null;
    }

    /**
     * Get random summary information for the question.
     *
     * @param object $question
     * @return string
     */
    public function get_random_guess_score($question) {
        return 0;
    }

    public function can_analyse_responses() {
        return false;
    }

    /**
     * Get possible responses for the question.
     *
     * @param object $question
     * @return array
     */
    public function get_possible_responses($question) {
        global $DB;
        
        $responses = array();
        
        // Get the question options to access gaps data
        $options = $DB->get_record('qtype_dictation_options', array('questionid' => $question->id));
        if (!$options || empty($options->gaps)) {
            return $responses;
        }
        
        $gaps = json_decode($options->gaps, true);
        if (!$gaps) {
            return $responses;
        }
        
        foreach ($gaps as $index => $gap) {
            // Handle multiple correct answers per gap
            $correctAnswers = is_array($gap) ? $gap : array($gap);
            $gapresponses = array();
            
            // Add each correct answer as a possible response with grade 1
            foreach ($correctAnswers as $correct) {
                $gapresponses[$correct] = new question_classified_response($correct, 1.0, $correct);
            }
            
            // Add null response for incorrect answers
            $gapresponses[null] = new question_classified_response(null, 0.0, get_string('incorrect', 'qtype_dictation'));
        
        }
        
        return $responses;
    }


    /**
     * Get extra question bank actions for this question type.
     * Adds CSV export link to question bank.
     *
     * @param object $question The question object
     * @param string $previewurl Preview URL for the question  
     * @param object $displaydata Display data for the question
     * @return string Additional HTML for question actions
     */
    public function get_extra_question_bank_actions(stdClass $question): array {
        global $OUTPUT;
        
        $actions = [];
        
        // Only show export link if question has attempts
        $attemptcount = $this->count_question_attempts($question->id);
        if ($attemptcount > 0) {
            $exporturl = new moodle_url('/question/type/dictation/export_csv.php', 
                array('questionid' => $question->id));
            $actions[] = $OUTPUT->action_link($exporturl, 
                'Export to CSV' . " ($attemptcount)", 
                null, 
                array('title' =>  'Export to CSV')
            );
        }
        
        return $actions;
    }
    
    /**
     * Count attempts for a given question.
     *
     * @param int $questionid Question ID
     * @return int Number of attempts
     */
    private function count_question_attempts($questionid) {
        global $DB;
        return $DB->count_records('qtype_dictation_attempts', array('questionid' => $questionid));
    }
}