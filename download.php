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
 * Course overview report
 *
 * @package    report
 * @subpackage courseaudit
 * @copyright  1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('reportcourseaudit', '', null, '', array('pagelayout'=>'report'));

$export = $DB->get_recordset_sql("SELECT cr.id, ca.name AS 'category',
        co.fullname AS 'course', co.startdate AS 'start date',
        cr.enrolnum AS 'students', cr.activenum AS 'active',
        cr.activepc AS 'active %', cr.lastupdate AS 'last updated',
        cr.linknum AS 'links', cr.filenum AS 'files', cr.foldernum AS 'folders',
        cr.headingnum AS 'headings', cr.labelnum AS 'labels',
        cr.pagenum AS 'web pages', cr.contentsnum AS 'contents block',
        cr.htmlnum AS 'html blocks', cr.rssnum AS 'rss feeds',
        cr.newsnum AS 'news threads', cr.booknum AS 'book chapters',
        cr.gallerynum AS 'galleries', cr.medianum AS 'media resources',
        cr.nlnnum AS 'nln materials', cr.imscpnum AS 'ims packages',
        cr.scormnum AS 'scorm objects', cr.hotpotnum AS 'hotpots',
        cr.choicenum AS 'polls', cr.questnum AS 'survey questions',
        cr.assignnum AS 'assignments', cr.quiznum AS 'quiz questions',
        cr.forumnum AS 'discussions', cr.chatnum AS 'chat rooms',
        cr.glossarynum AS 'glossary entries', cr.dbnum AS 'database entries',
        cr.wikinum AS 'wiki pages', cr.bscore AS 'bronze score',
        cr.sscore AS 'silver score', cr.gscore AS 'gold score',
        cr.rating AS 'rating', cr.override AS 'override'
        FROM {block_course_rating} cr
        INNER JOIN {course} co ON cr.courseid = co.id
        INNER JOIN {course_categories} ca ON co.category = ca.id
        ORDER by co.sortorder ASC");

if ($export->valid()) {
    $i = 0;
    $header = '';
    $data = '';

    foreach ($export as $course) {
        $line = '';
        foreach ($course as $field => $value) {
            if ($field == 'id') {
                continue;
            }
            if ($i == 0) {
                $header .= $field . "\t";
            }
            if ($field == 'start date' || $field == 'last updated') {
                if ($field == 'last updated' && $value == 0) {
                    $value = 'Never';
                } else {
                    $value = userdate($value, '%x');
                }
            }
            if (!isset($value) || $value == '') {
                $value = "\t";
            } else {
                $value = str_replace('"', '""', $value);
                $value = '"' . $value . '"' . "\t";
            }
            $line .= $value;
        }
        $i++;
        $data .= trim($line) . "\n";
    }
    $export->close(); // close recordset, free up resources

    $header = trim($header) . "\n";
    $data = str_replace("\r", '', $data);

} else {
    $header = '';
    $data = get_string('norecords', 'report_courseaudit');
}

header('Content-type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="course_audit_' . date(Ymd) . '.xls"');
header('Pragma: no-cache');
header('Expires: 0');

// clean output buffer to avoid any strange characters
ob_clean();
echo $header, $data;

?>