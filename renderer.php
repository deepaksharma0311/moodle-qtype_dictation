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
 * Dictation question renderer class.
 *
 * @package    qtype_dictation
 * @copyright  2025 Deepak Sharma <deepak@palinfocom.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Generates the output for dictation questions.
 */
class qtype_dictation_renderer extends qtype_renderer {

    /**
     * Generate the display of the formulation part of the question.
     *
     * @param question_attempt $qa the question attempt to display.
     * @param question_display_options $options controls what should and should not be displayed.
     * @return string HTML fragment.
     */
    public function formulation_and_controls(question_attempt $qa, question_display_options $options) {
        global $PAGE;

        $qaid = $qa->get_database_id();
        $question = $qa->get_question();
        $currentanswer = $qa->get_last_qt_data();
      
        $questiontext = $question->format_questiontext($qa);
        $result = html_writer::tag('div', $questiontext, array('class' => 'qtext'));

        // Add audio player if enabled
        if ($question->enableaudio && !empty($question->audiofile)) {
            $result .= $this->render_audio_player($question, $qa, $currentanswer);
        }

        // Add the question text with input gaps
        $result .= $this->render_question_text_with_gaps($question, $qa, $currentanswer);
    
        // Add JavaScript for audio control and form handling
        if($options->readonly!=1){
        $PAGE->requires->js_call_amd('qtype_dictation/dictation', 'init', array(
            'questionid' => $question->id,
            'maxplays' => $question->maxplays,
            'enableaudio' => $question->enableaudio,
            'qaid' => $qaid
        ));
        }
        if ($qa->get_state() == question_state::$invalid) {
            $result .= html_writer::nonempty_tag('div',
                $question->get_validation_error($currentanswer),
                array('class' => 'validationerror'));
        }

        return $result;
    }

    /**
     * Render the audio player component.
     *
     * @param qtype_dictation_question $question
     * @param question_attempt $qa
     * @param array $currentanswer
     * @return string HTML for audio player
     */
    private function render_audio_player($question, $qa, $currentanswer) {
        $playcount = isset($currentanswer['playcount']) ? (int)$currentanswer['playcount'] : 0;
        $maxplays = (int)$question->maxplays;
        $disabled = ($maxplays > 0 && $playcount >= $maxplays);

        $html = html_writer::start_tag('div', array('class' => 'dictation-audio-player','id' => 'q' . $question->id));
        
        // Audio element
        $audioattrs = array(
            'id' => 'dictation-audio-' . $question->id,
            'class' => 'dictation-audio',
            'preload' => 'metadata',

        );
        
        if ($disabled) {
            $audioattrs['disabled'] = 'disabled';
        }
        
        $html .= html_writer::start_tag('audio', $audioattrs);
        
        // Generate proper audio file URL
        if ($question->audiofile) {
            $audiourl = is_object($question->audiofile) ? $question->audiofile->out() : $question->audiofile;
            $html .= html_writer::empty_tag('source', array(
                'src' => $audiourl,
                'type' => 'audio/mpeg'
            ));
        }
        
        $html .= get_string('audionotsupported', 'qtype_dictation');
        $html .= html_writer::end_tag('audio');

        // Play button
        $buttonattrs = array(
            'type' => 'button',
            'id' => 'dictation-play-btn-' . $question->id,
            'class' => 'btn btn-primary audio-play-btn',
            'aria-label' => get_string('play', 'qtype_dictation'),
        );
        
        if ($disabled) {
            $buttonattrs['disabled'] = 'disabled';
            $buttonattrs['class'] .= ' disabled';
        }
        
        $buttontext = $disabled ? get_string('playlimitreached', 'qtype_dictation') : get_string('play', 'qtype_dictation');
        $html .= html_writer::tag('button', $buttontext, $buttonattrs);

        // Play counter
        if ($maxplays > 0) {
            // $countertext = get_string('playcount', 'qtype_dictation', array(
            //     'current' => $playcount,
            //     'max' => $maxplays
            // ));
            $countertext =  $playcount .' / '.$maxplays;
            $html .= html_writer::tag('span', $countertext, array(
                'class' => 'dictation-play-counter',
                'id' => 'dictation-counter-' . $question->id
            ));
        }

        // Hidden field to track play count
        $html .= html_writer::empty_tag('input', array(
            'type' => 'hidden',
            'name' => $qa->get_qt_field_name('playcount'),
            'id' => $qa->get_qt_field_name('playcount'),
            'value' => $playcount
        ));

        $html .= html_writer::end_tag('div');
        
        return $html;
    }

    /**
     * Render the question text with input gaps.
     *
     * @param qtype_dictation_question $question
     * @param question_attempt $qa
     * @param array $currentanswer
     * @return string HTML for question text with gaps
     */
    private function render_question_text_with_gaps($question, $qa, $currentanswer) {
        $text = $question->transcript;
        $gapindex = 0;
        
        $displaymode = isset($question->displaymode) ? $question->displaymode : 'standard';
        
        
        // Replace [word] with input boxes based on display mode
        $text = preg_replace_callback('/\[([^\]]+)\]/', function($matches) use (&$gapindex, $qa, $currentanswer, $displaymode, $question) {
            $fieldname = $qa->get_qt_field_name('gap_' . $gapindex);
            $currentvalue = isset($currentanswer['gap_' . $gapindex]) ? $currentanswer['gap_' . $gapindex] : '';
            //$correctword = $matches[1];
            // Handle multiple correct answers - use first one for display calculations
            $gapContent = $matches[1];
            $correctAnswers = strpos($gapContent, ',') !== false ? 
                array_map('trim', explode(',', $gapContent)) : array(trim($gapContent));
            $primaryWord = $correctAnswers[0]; // Use first answer for placeholder/sizing
            
            // Generate placeholder based on display mode
            $placeholder = $this->generate_gap_placeholder($primaryWord, $displaymode);
            
            // Set CSS class based on text alignment preference
            $cssclass = 'dictation-gap dictation-gap-' . $displaymode;
            if (!empty($question->leftaligntext)) {
                $cssclass .= ' dictation-gap-left-aligned';
            }
            //echo "<pre>";
            //print_r($question);
           // echo $question->enableaudio;
            if($question->enableaudio!=1){
                $cssclass .= ' ctype-question';
            }
            
            // Calculate width for length hints mode  
            $inputsize = max(8, strlen($primaryWord));
            
            if ($displaymode === 'length') {
                $inputsize = (strlen($primaryWord) * 1.5); // Add small buffer
            }
            // Generate placeholder based on display mode
           // $placeholder = $this->generate_gap_placeholder($correctword, $displaymode);
            $gapindexnew = $gapindex+1;
            $fontsize = 15; // ~.9375rem = 15px

          
            if($inputsize >0 ){
                $inputsize1 = (strlen($primaryWord) * 1.55); // Add small buffer
                $cc = $inputsize1+0.5 ;

            }
            if($inputsize >10 ){
                $inputsize1 = (strlen($primaryWord) * 1.52); // Add small buffer
                $cc = $inputsize1;

            }
            if($inputsize >20 ){

                $inputsize1 = (strlen($primaryWord) * 1.43); // Add small buffer
                $cc = $inputsize1;

            }
            if($inputsize >35 ){

               $inputsize1 = (strlen($primaryWord) * 1.38); // Add small buffer
                $cc = $inputsize1;

            }
            if($inputsize >40 ){

                $inputsize1 = (strlen($primaryWord) * 1.38); // Add small buffer
                $cc = $inputsize1;

            }


            if ($displaymode === 'standard') {
               $inputsize = max(8, strlen($primaryWord));

                    if($inputsize >0 ){
                        $inputsize1 = (strlen($primaryWord) * 1.4); // Add small buffer
                        $cc = $inputsize1+0.5 ;

                    }
                    if($inputsize >7 ){
                        $inputsize1 = (strlen($primaryWord) * 1.05); // Add small buffer
                        $cc = $inputsize1+0.5 ;

                    }
                    if($inputsize >10 ){
                        $inputsize1 = (strlen($primaryWord) * 1.05); // Add small buffer
                        $cc = $inputsize1;

                    }
                    if($inputsize >20 ){

                        $inputsize1 = (strlen($primaryWord) * 1.05); // Add small buffer
                        $cc = $inputsize1;

                    }
                    if($inputsize >35 ){

                    $inputsize1 = (strlen($primaryWord) * 1.05); // Add small buffer
                        $cc = $inputsize1;

                    }
                    if($inputsize >40 ){

                        $inputsize1 = (strlen($primaryWord) * 0.99  ); // Add small buffer
                        $cc = $inputsize1;

                    }
            }
            $widthstyle = 'width: ' . $cc . 'ch;'; // +10px padding buffer


             
            
            $inputhtml = html_writer::empty_tag('input', array(
                'type' => 'text',
                'name' => $fieldname,
                'id' => $fieldname,
                'value' => $currentvalue,
                'class' => $cssclass,
                'size' => $inputsize,
                'aria-label' => "Gap ".$gapindexnew,
                'placeholder' => $placeholder,
                'autocomplete' => 'off',
                /*'maxlength' => strlen($primaryWord), */
                
                //'data-correct-length' => strlen($correctword)
                'data-correct-length' => strlen($primaryWord),
                'title' => count($correctAnswers) > 1 ? 'Multiple answers accepted: ' : ''
            ));

            if (!empty($question->leftaligntext)) {
                $inputhtml = html_writer::empty_tag('input', array(
                'type' => 'text',
                'name' => $fieldname,
                'id' => $fieldname,
                'value' => $currentvalue,
                'class' => $cssclass,
                'size' => $inputsize,
                'aria-label' => "Gap ".$gapindexnew,
                'placeholder' => $placeholder,
                'autocomplete' => 'off',
                'style' => $widthstyle,
                /*'maxlength' => strlen($primaryWord), */
                //'data-correct-length' => strlen($correctword)
                'data-correct-length' => strlen($primaryWord),
                'title' => count($correctAnswers) > 1 ? 'Multiple answers accepted: ' : ''
            ));
            }
            $gapindex++;
         

            return $inputhtml;
        }, $text);
        
        return html_writer::tag('div', $text, array('class' => 'dictation-question-text'));
    }

    private function calculate_text_width($text, $fontsize = 15, $fontfile = '') {
    if (empty($fontfile) || !file_exists($fontfile)) {
        // Fallback if font missing
        return strlen($text) * ($fontsize * 0.6);
    }

    $box = imagettfbbox($fontsize, 0, $fontfile, $text);
    $width = abs($box[2] - $box[0]);
    return (int)$width;
}

    /**
     * Generate placeholder text for gaps based on display mode.
     *
     * @param string $correctword The correct word
     * @param string $displaymode Display mode setting
     * @return string Placeholder text
     */
    private function generate_gap_placeholder($correctword, $displaymode) {
        switch ($displaymode) {
            case 'length':
                // One underscore per letter: _ _ _ _
                //return str_repeat('_ ', strlen($correctword));
                $length = strlen($correctword);
                return trim(str_repeat('_ ', $length));
                
            case 'letters':
                // Show individual letter positions: g o _ _
                $length = strlen($correctword);
                if ($length <= 2) {
                    return str_repeat('_ ', $length);
                }
                // Show first letter, rest as underscores
                $result = substr($correctword, 0, 1) . ' ';
                for ($i = 1; $i < $length; $i++) {
                    $result .= '_ ';
                }
                return trim($result);
                
            case 'partial':
                // Show first half of letters for C-test style
                $length = strlen($correctword);
                $showlength = intval($length / 2);
                if ($showlength == 0) $showlength = 1;
                
                $result = substr($correctword, 0, $showlength);
                for ($i = $showlength; $i < $length; $i++) {
                    $result .= '_';
                }
                return $result;
                
            case 'standard':
            default:
                // Standard blank line
                return str_repeat('_', max(8, strlen($correctword)));
        }
    }

    /**
     * Generate the specific feedback.
     *
     * @param question_attempt $qa
     * @return string HTML fragment
     */
    public function specific_feedback(question_attempt $qa) {
        $question = $qa->get_question();
        $response = $qa->get_last_qt_data();
        
        if (empty($response) || !$qa->get_state()->is_finished()) {
            return '';
        }

        $feedback = $question->get_gap_feedback($response);
        $html = html_writer::start_tag('div', array('class' => 'dictation-feedback'));
        
        $html .= html_writer::tag('h4', get_string('feedback', 'qtype_dictation'));
        
        $html .= html_writer::start_tag('div', array('class' => 'dictation-feedback-details'));
        
        foreach ($feedback as $gapfeedback) {
            $class = $gapfeedback['iscorrect'] ? 'correct' : 'incorrect';
            
            $html .= html_writer::start_tag('div', array('class' => 'gap-feedback ' . $class));
            
            $html .= html_writer::tag('strong', get_string('gap', 'qtype_dictation') . ' ' . ($gapfeedback['gap'] + 1) . ':');
            
            $html .= html_writer::tag('div', 
                get_string('correct', 'qtype_dictation') . ': ' . $gapfeedback['correct'],
                array('class' => 'correct-answer')
            );
            
            $html .= html_writer::tag('div',
                get_string('youranswer', 'qtype_dictation') . ': ' . $gapfeedback['student'],
                array('class' => 'student-answer')
            );
            
            $html .= html_writer::tag('div',
                get_string('score', 'qtype_dictation') . ': ' . round($gapfeedback['score'] * 100, 1) . '%',
                array('class' => 'gap-score')
            );
            
            $html .= html_writer::end_tag('div');
        }
        
        $html .= html_writer::end_tag('div');
        $html .= html_writer::end_tag('div');
        
        return $html;
    }

    /**
     * Generate the correct answer display.
     *
     * @param question_attempt $qa
     * @return string HTML fragment
     */
    public function correct_response(question_attempt $qa) {
        $question = $qa->get_question();
        $correctresponse = $question->get_correct_response();
       
        if (empty($correctresponse)) {
            return '';
        }

        $html = html_writer::start_tag('div', array('class' => 'rightanswer'));
        $html .= html_writer::tag('strong', get_string('correctansweris', 'qtype_dictation'));
        
        $answers = array();
        for ($i = 0; $i < count($question->gaps); $i++) {
            $gapkey = 'gap_' . $i;
            if (isset($correctresponse[$gapkey])) {
                $answers[] = ($i + 1) . ': ' . $correctresponse[$gapkey][0];
            }
        }
        
        $html .= html_writer::tag('div', implode(', ', $answers));
        $html .= html_writer::end_tag('div');
        
        return $html;
    }
}