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
 * This file contains the backup user interface class
 *
 * @package   moodlecore
 * @copyright 2010 Sam Hemelryk
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * This is the backup user interface class
 *
 * The backup user interface class manages the user interface and backup for
 * Moodle.
 *
 * @copyright 2010 Sam Hemelryk
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_ui {
    /**
     * The stages of the backup user interface.
     */
    const STAGE_INITIAL = 1;
    const STAGE_SCHEMA = 2;
    const STAGE_CONFIRMATION = 4;
    const STAGE_FINAL = 8;
    /**
     * The progress of this instance of the backup ui class
     */
    const PROGRESS_INTIAL = 0;
    const PROGRESS_PROCESSED = 1;
    const PROGRESS_SAVED = 2;
    const PROGRESS_EXECUTED = 3;
    /**
     * The backup controller
     * @var backup_controller
     */
    protected $controller;
    /**
     * The current stage
     * @var backup_ui_stage
     */
    protected $stage;
    /**
     * The current progress of the UI
     * @var int One of self::PROGRESS_*
     */
    protected $progress;
    /**
     * The number of changes made by dependency enforcement
     * @var int
     */
    protected $dependencychanges = 0;

    /**
     * Yay for constructors
     * @param backup_controller $controller
     */
    public function __construct(backup_controller $controller) {
        $this->controller = $controller;
        $this->progress = self::PROGRESS_INTIAL;
        $this->stage = $this->initialise_stage();
    }
    /**
     * Intialises what ever stage is requested. If none are requested we check
     * params for 'stage' and default to initial
     *
     * @param int|null $stage The desired stage to intialise or null for the default
     * @return backup_ui_stage_initial|backup_ui_stage_schema|backup_ui_stage_confirmation|backup_ui_stage_final
     */
    protected function initialise_stage($stage = null) {
        if ($stage == null) {
            $stage = optional_param('stage', self::STAGE_INITIAL, PARAM_INT);
        }
        switch ($stage) {
            case backup_ui::STAGE_INITIAL:
                $stage = new backup_ui_stage_initial($this);
                break;
            case backup_ui::STAGE_SCHEMA:
                $stage = new backup_ui_stage_schema($this);
                break;
            case backup_ui::STAGE_CONFIRMATION:
                $stage = new backup_ui_stage_confirmation($this);
                break;
            case backup_ui::STAGE_FINAL:
                $stage = new backup_ui_stage_final($this);
                break;
            default:
                $stage = false;
                break;
        }
        return $stage;
    }
    /**
     * This passes off processing for the current stage to the previous stage.
     *
     * This occurs when the current stage hasn't been completed yet
     *
     * @param backup_ui_stage $stage
     * @return bool
     */
    public function process_previous_stage(backup_ui_stage $stage) {
        $prevstage = $stage->get_prev_stage();
        if ($prevstage) {
            $prevstage = $this->initialise_stage($prevstage);
            if ($prevstage) {
                return $prevstage->process();
            }
        }
        return false;
    }
    /**
     * This magical function processes all previous stages to the provided stage
     * given its backup_moodleform
     *
     * @param backup_ui_stage $stage
     * @param backup_moodleform $form
     * @return int The number of changes made by the user
     */
    public function process_all_previous_stages(backup_ui_stage $stage, backup_moodleform $form) {
        $stages = array();
        // First get an instance of each previous stage
        while ($stage instanceof backup_ui_stage) {
            $stage = $stage->get_prev_stage();
            if ($stage) {
                $stage = $this->initialise_stage($stage);
                $stages[] = $stage;
            }
        }
        $stages = array_reverse($stages);
        $changes = 0;
        // The process each stage in the correct order.
        foreach ($stages as $stage) {
            $outcome = $stage->process($form);
            // Check it didn't fail
            if ($outcome === false) {
                throw new backup_ui_exception('backup_ui_process_all_previous_stages_failed', $stage->get_stage());
            }
            $changes += $outcome;
        }
        return $changes;
    }
    /**
     * This processes the current stage of the backup
     * @return bool
     */
    public function process() {
        if ($this->progress >= self::PROGRESS_PROCESSED) {
            throw new backup_ui_exception('backupuialreadyprocessed');
        }
        $this->progress = self::PROGRESS_PROCESSED;
        return $this->stage->process();
    }
    /**
     * Saves the backup controller.
     *
     * Once this has been called nothing else can be changed in the controller.
     *
     * @return bool
     */
    public function save_controller() {
        if ($this->progress >= self::PROGRESS_SAVED) {
            throw new backup_ui_exception('backupuialreadysaved');
        }
        $this->progress = self::PROGRESS_SAVED;
        // First enforce dependencies
        $this->enforce_dependencies();
        // Save the controller
        $this->controller->save_controller();
        return true;
    }
    /**
     * Displays the UI for the backup!
     *
     * Note: The UI makes use of mforms (ewww!) thus it will automatically print
     * out the result rather than returning a string of HTML like other parts of Moodle
     *
     * @return bool
     */
    public function display() {
        if ($this->progress < self::PROGRESS_SAVED) {
            throw new backup_ui_exception('backupsavebeforedisplay');
        }
        $this->stage->display();
    }
    /**
     * Gets all backup tasks from the controller
     * @return array Array of backup_task
     */
    public function get_backup_tasks() {
        $plan = $this->controller->get_plan();
        $tasks = $plan->get_tasks();
        return $tasks;
    }
    /**
     * Gets the stage we are on
     * @return backup_ui_stage
     */
    public function get_stage() {
        return $this->stage->get_stage();
    }
    /**
     * Gets the name of the stage we are on
     * @return string
     */
    public function get_stage_name() {
        return $this->stage->get_name();
    }
    /**
     * Gets the backup id from the controller
     * @return string
     */
    public function get_backupid() {
        return $this->controller->get_backupid();
    }
    /**
     * Executes the backup plan
     * @return bool
     */
    public function execute() {
        if ($this->progress >= self::PROGRESS_EXECUTED) {
            throw new backup_ui_exception('backupuialreadyexecuted');
        }
        if ($this->stage->get_stage() < self::STAGE_FINAL) {
            throw new backup_ui_exception('backupuifinalisedbeforeexecute');
        }
        $this->progress = self::PROGRESS_EXECUTED;
        $this->controller->finish_ui();
        $this->controller->execute_plan();
        $this->stage = new backup_ui_stage_complete($this, $this->controller->get_results());
        return true;
    }
    /**
     * Enforces dependencies on all settings. Call before save
     * @return bool True if dependencies were enforced and changes were made
     */
    protected function enforce_dependencies() {
        // Get the plan
        $plan = $this->controller->get_plan();
        // Get the tasks as a var so we can iterate by reference
        $tasks = $plan->get_tasks();
        $changes = 0;
        foreach ($tasks as &$task) {
            // Store as a var so we can iterate by reference
            $settings = $task->get_settings();
            foreach ($settings as &$setting) {
                // Get all dependencies for iteration by reference
                $dependencies = $setting->get_dependencies();
                foreach ($dependencies as &$dependency) {
                    // Enforce each dependency
                    if ($dependency->enforce()) {
                        $changes++;
                    }
                }
            }
        }
        // Store the number of settings that changed through enforcement
        $this->dependencychanges = $changes;
        return ($changes>0);
    }
    /**
     * Returns true if enforce_dependencies changed any settings
     * @return bool
     */
    public function enforce_changed_dependencies() {
        return ($this->dependencychanges > 0);
    }
    /**
     * Loads the backup controller if we are tracking one
     * @return backup_controller|false
     */
    final public static function load_controller() {
        // Get the backup id optional param
        $backupid = optional_param('backup', false, PARAM_ALPHANUM);
        if ($backupid) {
            try {
                // Try to load the controller with it.
                // If it fails at this point it is likely because this is the first load
                $controller = backup_controller::load_controller($backupid);
                return $controller;
            } catch (Exception $e) {
                return false;
            }
        }
        return $backupid;
    }
    /**
     * Cancels the current backup and redirects the user back to the relevant place
     */
    public function cancel_backup() {
        global $PAGE;
        // Determine the approriate URL to redirect the user to
        if ($PAGE->context->contextlevel == CONTEXT_MODULE && $PAGE->cm !== null) {
            $relevanturl = new moodle_url('/mod/'.$PAGE->cm->modname.'/view.php', array('id'=>$PAGE->cm->id));
        } else {
            $relevanturl = new moodle_url('/course/view.php', array('id'=>$PAGE->course->id));
        }
        redirect($relevanturl);
    }
    /**
     * Gets an array of progress bar items that can be displayed through the backup renderer.
     * @return array Array of items for the progress bar
     */
    public function get_progress_bar() {
        $stage = self::STAGE_FINAL;
        $currentstage = $this->stage->get_stage();
        $items = array();
        while ($stage > 0) {
            $classes = array('backup_stage');
            if (floor($stage/2) == $currentstage) {
                $classes[] = 'backup_stage_next';
            } else if ($stage == $currentstage) {
                $classes[] = 'backup_stage_current';
            } else if ($stage < $currentstage) {
                $classes[] = 'backup_stage_complete';
            }
            array_unshift($items, array(
                'text' => get_string('currentstage'.$stage, 'backup'),
                'class' => join(' ', $classes)
            ));
            $stage = floor($stage/2);
        }
        return $items;
    }
}

/**
 * Backup user interface exception. Modelled off the backup_exception class
 */
class backup_ui_exception extends backup_exception {}