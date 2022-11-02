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
 * Question behaviour where the student can submit questions one at a
 * time for immediate feedback, with certainty based marking for MooPT questions.
 *
 * @package    qbehaviour
 * @subpackage immediatemooptcbm
 * @copyright  2009 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../immediatemoopt/behaviour.php');


/**
 * Question behaviour for immediate feedback with CBM for MooPT questions.
 *
 * Each question has a submit button next to it along with some radio buttons
 * to input a certainty, that is, how sure they are that they are right.
 * The student can submit their answer at any time for immediate feedback.
 * Once the qustion is submitted, it is not possible for the student to change
 * their answer any more. The student's degree of certainty affects their score.
 *
 * @copyright  2009 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qbehaviour_immediatemooptcbm extends qbehaviour_immediatemoopt {
    public function get_min_fraction() {
        return question_cbm::adjust_fraction(parent::get_min_fraction(), question_cbm::HIGH);
    }

    public function get_max_fraction() {
        return question_cbm::adjust_fraction(parent::get_max_fraction(), question_cbm::HIGH);
    }

    public function get_expected_data() {
        if ($this->qa->get_state()->is_active()) {
            return array(
                'submit' => PARAM_BOOL,
                'certainty' => PARAM_INT,
            );
        }
        return parent::get_expected_data();
    }

    public function get_right_answer_summary() {
        $summary = parent::get_right_answer_summary();
        return question_cbm::summary_with_certainty($summary, question_cbm::HIGH);
    }

    public function get_correct_response() {
        if ($this->qa->get_state()->is_active()) {
            return array('certainty' => question_cbm::HIGH);
        }
        return array();
    }

    protected function get_our_resume_data() {
        $lastcertainty = $this->qa->get_last_behaviour_var('certainty');
        if ($lastcertainty) {
            return array('-certainty' => $lastcertainty);
        } else {
            return array();
        }
    }

    protected function is_same_response(question_attempt_step $pendingstep) {
        return parent::is_same_response($pendingstep) &&
                $this->qa->get_last_behaviour_var('certainty') ==
                        $pendingstep->get_behaviour_var('certainty');
    }

    protected function is_complete_response(question_attempt_step $pendingstep) {
        return parent::is_complete_response($pendingstep) &&
                $pendingstep->has_behaviour_var('certainty');
    }

    public function process_submit(question_attempt_pending_step $pendingstep) {
        global $DB;

        if ($this->qa->get_state()->is_finished()) {
            return question_attempt::DISCARD;
        }

        if (!$this->question->is_gradable_response($pendingstep->get_qt_data()) ||
                !$pendingstep->has_behaviour_var('certainty')) {
            $pendingstep->set_state(question_state::$invalid);
        } else {
            //initiate grading
            $response = $pendingstep->get_qt_data();
            if ($this->question->enablefilesubmissions) {
                $questionfilesaver = $pendingstep->get_qt_var('answer');
                if ($questionfilesaver instanceof question_file_saver) {
                    $responsefiles = $questionfilesaver->get_files();
                } else {
                    // We are in a regrade.
                    $record = $DB->get_record('question_usages', array('id' => $this->qa->get_usage_id()), 'contextid');
                    $qubacontextid = $record->contextid;
                    $responsefiles = $pendingstep->get_qt_files('answer', $qubacontextid);
                }
            }
            $freetextanswers = [];
            if ($this->question->enablefreetextsubmissions) {
                $autogeneratenames = $this->question->ftsautogeneratefilenames;
                for ($i = 0; $i < $this->question->ftsmaxnumfields; $i++) {
                    $text = $response["answertext$i"];
                    if ($text == '') {
                        continue;
                    }
                    $record = $DB->get_record('qtype_moopt_freetexts',
                        ['questionid' => $this->question->id, 'inputindex' => $i]);
                    $filename = $response["answerfilename$i"] ?? '';        // By default use submitted filename.
                    // Overwrite filename if necessary.
                    if ($record) {
                        if ($record->presetfilename) {
                            $filename = $record->filename;
                        } else if ($filename == '') {
                            $tmp = $i + 1;
                            $filename = "File$tmp.txt";
                        }
                    } else if ($autogeneratenames || $filename == '') {
                        $tmp = $i + 1;
                        $filename = "File$tmp.txt";
                    }
                    $freetextanswers[$filename] = $text;
                }
            }

            $state = $this->question->grade_response_asynch($this->qa, $responsefiles ?? [], $freetextanswers);
            $pendingstep->set_state($state);
            $pendingstep->set_new_response_summary($this->question->summarise_response($response));
        }

        return question_attempt::KEEP;
    }


    public function process_gradingresult(question_attempt_pending_step $pendingstep) {
        $status = parent::process_gradingresult($pendingstep);

        if ($status == question_attempt::KEEP) {
            $fraction = $pendingstep->get_fraction();
            if ($this->qa->get_last_step()->has_behaviour_var('certainty')) {
                $certainty = $this->qa->get_last_step()->get_behaviour_var('certainty');
            } else {
                $certainty = question_cbm::default_certainty();
                $pendingstep->set_behaviour_var('_assumedcertainty', $certainty);
            }

            if (!is_null($fraction)) {
                $pendingstep->set_behaviour_var('_rawfraction', $fraction);
                $pendingstep->set_fraction(question_cbm::adjust_fraction($fraction, $certainty));
            }
            $pendingstep->set_new_response_summary(
                question_cbm::summary_with_certainty($pendingstep->get_new_response_summary(), $certainty));
        }
        return $status;
    }


    public function summarise_action(question_attempt_step $step) {
        $summary = parent::summarise_action($step);
        if ($step->has_behaviour_var('certainty')) {
            $summary = question_cbm::summary_with_certainty($summary,
                    $step->get_behaviour_var('certainty'));
        }
        return $summary;
    }
}
