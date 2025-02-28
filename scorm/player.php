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
 * player file
 *
 * @package    local_kopere_mobile
 * @copyright  2024 Eduardo Kraus {@link http://eduardokraus.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable

// This page prints a particular instance of aicc/scorm package.

require_once('../../../config.php');
require_once($CFG->dirroot . '/mod/scorm/locallib.php');
require_once($CFG->libdir . '/completionlib.php');

$SESSION->local_kopere_mobile_preserve_page = false;
$SESSION->kopere_mobile_redirect_page = false;

$id = optional_param('cm', '', PARAM_INT);                          // Course Module ID, or.
$a = optional_param('a', '', PARAM_INT);                            // Scorm ID.
$scoid = required_param('scoid', PARAM_INT);                        // Sco ID.
$mode = optional_param('mode', 'normal', PARAM_ALPHA);              // Navigation mode.
$currentorg = optional_param('currentorg', '', PARAM_RAW);          // Selected organization.
$newattempt = optional_param('newattempt', 'off', PARAM_ALPHA);     // The user request to start a new attempt.
$displaymode = optional_param('display', 'popup', PARAM_ALPHA);

if (!empty($id)) {
    if (!$cm = get_coursemodule_from_id('scorm', $id, 0, true)) {
        throw new \moodle_exception('invalidcoursemodule');
    }
    if (!$course = $DB->get_record("course", ["id" => $cm->course])) {
        throw new \moodle_exception('coursemisconf');
    }
    if (!$scorm = $DB->get_record("scorm", ["id" => $cm->instance])) {
        throw new \moodle_exception('invalidcoursemodule');
    }
} else if (!empty($a)) {
    if (!$scorm = $DB->get_record("scorm", ["id" => $a])) {
        throw new \moodle_exception('invalidcoursemodule');
    }
    if (!$course = $DB->get_record("course", ["id" => $scorm->course])) {
        throw new \moodle_exception('coursemisconf');
    }
    if (!$cm = get_coursemodule_from_instance("scorm", $scorm->id, $course->id, true)) {
        throw new \moodle_exception('invalidcoursemodule');
    }
} else {
    throw new \moodle_exception('missingparameter');
}

// PARAM_RAW is used for $currentorg, validate it against records stored in the table.
if (!empty($currentorg)) {
    if (!$DB->record_exists('scorm_scoes', ['scorm' => $scorm->id, 'identifier' => $currentorg])) {
        $currentorg = '';
    }
}

// If new attempt is being triggered set normal mode and increment attempt number.
$attempt = scorm_get_last_attempt($scorm->id, $USER->id);

// Check mode is correct and set/validate mode/attempt/newattempt (uses pass by reference).
scorm_check_mode($scorm, $newattempt, $attempt, $USER->id, $mode);

if (!empty($scoid)) {
    $scoid = scorm_check_launchable_sco($scorm, $scoid);
}

$url = new moodle_url('/mod/scorm/player.php', ['scoid' => $scoid, 'cm' => $cm->id]);
if ($mode !== 'normal') {
    $url->param('mode', $mode);
}
if ($currentorg !== '') {
    $url->param('currentorg', $currentorg);
}
if ($newattempt !== 'off') {
    $url->param('newattempt', $newattempt);
}
if ($displaymode !== '') {
    $url->param('display', $displaymode);
}
$PAGE->set_url($url);
// $PAGE->set_secondary_active_tab("modulepage");

$forcejs = get_config('scorm', 'forcejavascript');
if (!empty($forcejs)) {
    $PAGE->add_body_class('forcejavascript');
}
$collapsetocwinsize = get_config('scorm', 'collapsetocwinsize');
if (empty($collapsetocwinsize)) {
    // Set as default window size to collapse TOC.
    $collapsetocwinsize = 767;
} else {
    $collapsetocwinsize = intval($collapsetocwinsize);
}

require_login($course, false, $cm);

$strscorms = get_string('modulenameplural', 'scorm');
$strscorm = get_string('modulename', 'scorm');
$strpopup = get_string('popup', 'scorm');
$strexit = get_string('exitactivity', 'scorm');

$coursecontext = context_course::instance($course->id);

if ($displaymode == 'popup') {
    $PAGE->set_pagelayout('embedded');
} else {
    $shortname = format_string($course->shortname, true, ['context' => $coursecontext]);
    $pagetitle = strip_tags("$shortname: " . format_string($scorm->name));
    $PAGE->set_title($pagetitle);
    $PAGE->set_heading($course->fullname);
}
if (!$cm->visible && !has_capability('moodle/course:viewhiddenactivities', context_module::instance($cm->id))) {
    echo $OUTPUT->header();
    notice(get_string("activityiscurrentlyhidden"));
    echo $OUTPUT->footer();
    die;
}

// Check if SCORM available.
list($available, $warnings) = scorm_get_availability_status($scorm);
if (!$available) {
    $reason = current(array_keys($warnings));
    echo $OUTPUT->header();
    echo $OUTPUT->box(get_string($reason, "scorm", $warnings[$reason]), "generalbox boxaligncenter");
    echo $OUTPUT->footer();
    die;
}

// TOC processing
$scorm->version = strtolower(clean_param($scorm->version, PARAM_SAFEDIR));   // Just to be safe.
if (!file_exists($CFG->dirroot . '/mod/scorm/datamodels/' . $scorm->version . 'lib.php')) {
    $scorm->version = 'scorm_12';
}
require_once($CFG->dirroot . '/mod/scorm/datamodels/' . $scorm->version . 'lib.php');

$result = scorm_get_toc($USER, $scorm, $cm->id, TOCJSLINK, $currentorg, $scoid, $mode, $attempt, true, true);
$sco = $result->sco;
if ($scorm->lastattemptlock == 1 && $result->attemptleft == 0) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('exceededmaxattempts', 'scorm'));
    echo $OUTPUT->footer();
    exit;
}

$scoidstr = '&amp;scoid=' . $sco->id;
$modestr = '&amp;mode=' . $mode;

$SESSION->scorm = new stdClass();
$SESSION->scorm->scoid = $sco->id;
$SESSION->scorm->scormstatus = 'Not Initialized';
$SESSION->scorm->scormmode = $mode;
$SESSION->scorm->attempt = $attempt;

// Mark module viewed.
$completion = new completion_info($course);
$completion->set_module_viewed($cm);

// Generate the exit button.
$exiturl = "";
if (empty($scorm->popup) || $displaymode == 'popup') {
    if ($course->format == 'singleactivity' && $scorm->skipview == SCORM_SKIPVIEW_ALWAYS
        && !has_capability('mod/scorm:viewreport', context_module::instance($cm->id))) {
        // Redirect students back to site home to avoid redirect loop.
        $exiturl = $CFG->wwwroot;
    } else {
        // Redirect back to the correct section if one section per page is being used.
        $exiturl = course_get_url($course, $cm->sectionnum)->out();
    }
}

// Print the page header.
$PAGE->requires->data_for_js('scormplayerdata', Array('launch' => false,
    'currentorg' => '',
    'sco' => 0,
    'scorm' => 0,
    'courseid' => $scorm->course,
    'cwidth' => $scorm->width,
    'cheight' => $scorm->height,
    'popupoptions' => $scorm->options), true);
$PAGE->requires->js('/mod/scorm/request.js', true);
$PAGE->requires->js('/lib/cookies.js', true);

if (file_exists($CFG->dirroot . '/mod/scorm/datamodels/' . $scorm->version . '.js')) {
    $PAGE->requires->js('/mod/scorm/datamodels/' . $scorm->version . '.js', true);
} else {
    $PAGE->requires->js('/mod/scorm/datamodels/scorm_12.js', true);
}
echo $OUTPUT->header();

$PAGE->requires->string_for_js('navigation', 'scorm');
$PAGE->requires->string_for_js('toc', 'scorm');
$PAGE->requires->string_for_js('hide', 'moodle');
$PAGE->requires->string_for_js('show', 'moodle');
$PAGE->requires->string_for_js('popupsblocked', 'scorm');

$name = false;

// Exit button should ONLY be displayed when in the current window.
//if ($displaymode !== 'popup') {
//    $renderer = $PAGE->get_renderer('mod_scorm');
//    echo $renderer->generate_exitbar($exiturl);
//}

echo html_writer::start_div('', ['id' => 'scormpage']);
echo html_writer::start_div('', ['id' => 'tocbox']);
echo html_writer::div(html_writer::tag('script', '', ['id' => 'external-scormapi', 'type' => 'text/JavaScript']), '',
    ['id' => 'scormapi-parent']);

//if ($scorm->hidetoc == SCORM_TOC_POPUP || $mode == 'browse' || $mode == 'review') {
//    echo html_writer::start_div('mb-3', ['id' => 'scormtop']);
//    if ($mode == 'browse' || $mode == 'review') {
//        echo html_writer::div(get_string("{$mode}mode", 'scorm'), 'scorm-left h3', ['id' => 'scormmode']);
//    }
//    if ($scorm->hidetoc == SCORM_TOC_POPUP) {
//        echo html_writer::div($result->tocmenu, 'scorm-right', ['id' => 'scormnav']);
//    }
//    echo html_writer::end_div();
//}

echo html_writer::start_div('', ['id' => 'toctree']);

if (empty($scorm->popup) || $displaymode == 'popup') {
    echo $result->toc;
} else {
    // Added incase javascript popups are blocked we don't provide a direct link...
    // To the pop-up as JS communication can fail - the user must disable their pop-up blocker.
    $linkcourse = html_writer::link($CFG->wwwroot . '/course/view.php?id=' .
        $scorm->course, get_string('finishscormlinkname', 'scorm'));
    echo $OUTPUT->box(get_string('finishscorm', 'scorm', $linkcourse), 'generalbox', 'altfinishlink');
}
echo html_writer::end_div(); // Toc tree ends.
echo html_writer::end_div(); // Toc box ends.
echo html_writer::tag('noscript', html_writer::div(get_string('noscriptnoscorm', 'scorm'), '', ['id' => 'noscript']));

if ($result->prerequisites) {
    if ($scorm->popup != 0 && $displaymode !== 'popup') {
        // Clean the name for the window as IE is fussy.
        $name = preg_replace("/[^A-Za-z0-9]/", "", $scorm->name);
        if (!$name) {
            $name = 'DefaultPlayerWindow';
        }
        $name = 'scorm_' . $name;
        echo html_writer::script('', $CFG->wwwroot . '/mod/scorm/player.js');
        $url = new moodle_url($PAGE->url, ['scoid' => $sco->id, 'display' => 'popup', 'mode' => $mode]);
        echo html_writer::script(
            js_writer::function_call('scorm_openpopup', [$url->out(false),
                $name, $scorm->options,
                $scorm->width, $scorm->height
            ]));
        echo html_writer::tag('noscript', html_writer::tag('iframe', '', [
            'id' => 'main',
            'class' => 'scoframe',
            'name' => 'main',
            'src' => "loadSCO.php?id={$cm->id}{$scoidstr}{$modestr}",
        ]));
    }
} else {
    echo $OUTPUT->box(get_string('noprerequisites', 'scorm'));
}
echo html_writer::end_div(); // Scorm page ends.

$scoes = scorm_get_toc_object($USER, $scorm, $currentorg, $sco->id, $mode, $attempt);
$adlnav = scorm_get_adlnav_json($scoes['scoes']);

if (empty($scorm->popup) || $displaymode == 'popup') {
    if (!isset($result->toctitle)) {
        $result->toctitle = get_string('toc', 'scorm');
    }
    $jsmodule = [
        'name' => 'mod_scorm',
        'fullpath' => '/local/kopere_mobile/scorm/module.js',
        'requires' => ['json'],
    ];
    $scorm->nav = intval($scorm->nav);
    $PAGE->requires->js_init_call('M.mod_scorm.init', array($scorm->nav, $scorm->navpositionleft, $scorm->navpositiontop,
        $scorm->hidetoc, $collapsetocwinsize, $result->toctitle, $name, $sco->id, $adlnav), false, $jsmodule);
}
if (!empty($forcejs)) {
    $message = $OUTPUT->box(get_string("forcejavascriptmessage", "scorm"), "generalbox boxaligncenter forcejavascriptmessage");
    echo html_writer::tag('noscript', $message);
}

if (file_exists($CFG->dirroot . '/mod/scorm/datamodels/' . $scorm->version . '.php')) {
    include_once($CFG->dirroot . '/mod/scorm/datamodels/' . $scorm->version . '.php');
} else {
    include_once($CFG->dirroot . '/mod/scorm/datamodels/scorm_12.php');
}

// Add the keepalive system to keep checking for a connection.
\core\session\manager::keepalive('networkdropped', 'mod_scorm', 30, 10);

echo $OUTPUT->footer();

// Set the start time of this SCO.
scorm_insert_track($USER->id, $scorm->id, $scoid, $attempt, 'x.start.time', time());
