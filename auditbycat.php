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

if (!$cat = required_param('cat', PARAM_INT)) {
    redirect($CFG->wwwroot . '/report/courseaudit/index.php');
}

admin_externalpage_setup('reportcourseaudit', '', null, '', array('pagelayout'=>'report'));
echo $OUTPUT->header();

// get course category name
$catname = $DB->get_field('course_categories', 'name', array('id'=>$cat));

echo "<div style='width: 80%;'>
        <p>The tables below contain the courses from the '$catname' category and its sub-categories.</p>
        <p>The 'Current rating' reflects what is currently recorded for each course in the database.
        This was updated last time the audit was processed. The courses have been automatically
        rated and the 'Auto-calculated rating' column shows the new ratings. Please use the course links
        below to moderate the courses and if you don't agree with the auto-calculated rating use
        the 'Override rating' column to override the rating. To remove a previously set override, select
        the first (blank) option, and the auto-calculated rating will be used instead. To exclude a
        course from the audit process altogether, select the 'Exclude' override option. This will
        remove the course from the 'Total courses' count on the summary page.</p>
        <p>Press the 'Process ratings' button at the bottom of the page to update the ratings.</p>
      </div>";

echo '<form method="post" action="' . $CFG->wwwroot . '/report/courseaudit/index.php" name="audit_process_form">';

// get sub-categories, excluding ones we aren't interested in
$subcats = $DB->get_recordset_sql("SELECT id, name FROM {course_categories}
        WHERE (path LIKE '/$cat/%' OR id = $cat)
        AND name NOT LIKE '%child courses%'
        AND name NOT LIKE '%staff only%'
        AND name NOT LIKE '%sandbox%' ORDER BY path");

$novalidcourses = true;

// get course ids from current and sub-categories to insert new ratings rows
foreach ($subcats as $subcat) {
    $subcatid = $subcat->id;
    $subcatname = $subcat->name;

    $courses = $DB->get_recordset('course', array('category'=>$subcatid, 'visible'=>1),
            'sortorder ASC', 'id');

    if ($courses->valid()) { // skip sub-category if it contains no visible courses
        $novalidcourses = false;
        // loop through and create rows
        foreach ($courses as $course) {
            $courseid = $course->id;

            // check if ratings row already exists
            if (!$DB->record_exists('block_course_rating', array('courseid'=>$courseid))) {
                // create data object and insert row
                $rating = new stdClass;
                $rating->courseid = $courseid;
                $DB->insert_record('block_course_rating', $rating, false);
            }
        }
        $courses->close();

        // get course details from category for display
        $coursedetails = $DB->get_recordset_sql("SELECT {course}.id, {course}.fullname,
                {course}.startdate, {block_course_rating}.rating, {block_course_rating}.override
                FROM {course} INNER JOIN {block_course_rating}
                ON {course}.id = {block_course_rating}.courseid
                WHERE {course}.category = $subcatid
                AND {course}.visible = 1
                ORDER BY {course}.sortorder");

        echo '<h3>' . $subcatname . '</h3>';

        echo '<table class="generaltable" border="1" cellspacing="0" cellpadding="2" width="80%">
                  <tr>
                      <th class="header" style="width: 50%;">Course (click to visit)</td>
                      <th class="header" style="white-space: nowrap;">Current rating</td>
                      <th class="header" style="white-space: nowrap;">Auto-calculated rating</td>
                      <th class="header" style="white-space: nowrap;">Override rating</td>
                  </tr>';

        // loop through and process audit stats for courses
        foreach ($coursedetails as $coursedetail) {
            $courseid = $coursedetail->id;
            $coursefullname = $coursedetail->fullname;
            $coursestartdate = $coursedetail->startdate;
            $oldrating = $coursedetail->rating;
            $override = $coursedetail->override;

            // start by getting number of enrolled students (or applicants)
            $enrolments = $DB->get_recordset_sql("SELECT {role_assignments}.userid
                    FROM {role_assignments}
                    INNER JOIN {context} ON {role_assignments}.contextid = {context}.id
                    INNER JOIN {course} ON {context}.instanceid = {course}.id
                    WHERE ({role_assignments}.roleid = 5
                    OR {role_assignments}.roleid = 14)
                    AND {context}.contextlevel = 50
                    AND {course}.id = $courseid");

            // find total number of students and percentage of 'active' students
            // (i.e. those who've accessed in last 30 days)
            $enrolcount = 0;
            $activecount = 0;
            $monthago = time() - (30 * 24 * 60 * 60);

            foreach ($enrolments as $enrolment) {
                $userid = $enrolment->userid;
                $active = $DB->count_records_sql("SELECT COUNT(*) FROM {log}
                        WHERE course = $courseid AND userid = $userid AND time > $monthago");
                if ($active > 0) {
                    $activecount++;
                }
                $enrolcount++;
            }
            $enrolments->close();

            // calculate active enrolments as percentage of total
            if ($activecount > 0) {
                $activepc = round(($activecount / $enrolcount) * 100);
            } else {
                $activepc = 0;
            }

            // find out when course content was last updated
            $lastupdate = $DB->get_field_sql("SELECT MAX(time)
                    FROM {log}
                    WHERE course = $courseid
                    AND module = 'course'
                    AND (action = 'add mod' OR action = 'update mod' OR action = 'delete mod')");

            // create data object for update
            $rating = new stdClass;
            $rating->id         = $DB->get_field('block_course_rating', 'id', array('courseid'=>$courseid));
            $rating->enrolnum   = $enrolcount;
            $rating->activenum  = $activecount;
            $rating->activepc   = $activepc;
            if ($lastupdate) {
                $rating->lastupdate = $lastupdate;
            } else {
                $rating->lastupdate = 0;
            }

            // bronze stats
            $bookcount = $DB->count_records_sql("SELECT COUNT({book_chapters}.id)
                    FROM {book_chapters}
                    INNER JOIN {book} ON {book_chapters}.bookid = {book}.id
                    WHERE {book_chapters}.content IS NOT NULL
                    AND TRIM({book_chapters}.content) <> ''
                    AND {book_chapters}.content NOT REGEXP '^ +$'
                    AND {book_chapters}.content NOT LIKE '%<object%'
                    AND {book_chapters}.content NOT LIKE '%<embed%'
                    AND {book_chapters}.content NOT LIKE '%mso%'
                    AND {book}.course = $courseid");

            $contentscount = $DB->count_records_sql("SELECT COUNT({block_instances}.id)
                    FROM {block_instances}
                    INNER JOIN {context} ON {block_instances}.parentcontextid = {context}.id
                    WHERE {context}.instanceid = $courseid
                    AND {context}.contextlevel = 50
                    AND {block_instances}.blockname = 'course_contents'
                    AND {block_instances}.pagetypepattern LIKE 'course-%'");

            $filecount = $DB->count_records_sql("SELECT COUNT({files}.id)
                    FROM {files}
                    INNER JOIN {context} ON {files}.contextid = {context}.id
                    INNER JOIN {course_modules} ON {context}.instanceid = {course_modules}.id
                    WHERE {course_modules}.course = $courseid
                    AND {context}.contextlevel = 70
                    AND {files}.component = 'mod_resource'
                    AND {files}.filename NOT REGEXP '\\.(3gp|aac|aif|asf|avi|flac|flv|m4a|m4p|m4v|mid|mkv|mov|mp3|mp4|mpe|mpeg|mpg|mts|ogg|ogv|ram|rm|swf|wav|wma|wmv)$'
                    AND {files}.filename NOT LIKE '.'");

            $foldercount = $DB->count_records('folder', array('course'=>$courseid));

            $gallerycount = $DB->count_records('lightboxgallery', array('course'=>$courseid));

            $headingcount = $DB->count_records_sql("SELECT COUNT(*)
                    FROM {course_sections}
                    WHERE name IS NOT NULL
                    AND TRIM(name) <> ''
                    AND summary NOT REGEXP '<object|<embed|\\.(3gp|aac|aif|asf|avi|flac|flv|m4a|m4p|m4v|mid|mkv|mov|mp3|mp4|mpe|mpeg|mpg|mts|ogg|ogv|ram|rm|swf|wav|wma|wmv)\"'
                    AND summary NOT LIKE '%mso%'
                    AND course = $courseid");

            $htmlcount = $DB->count_records_sql("SELECT COUNT({block_instances}.id)
                    FROM {block_instances}
                    INNER JOIN {context} ON {block_instances}.parentcontextid = {context}.id
                    WHERE {context}.instanceid = $courseid
                    AND {context}.contextlevel = 50
                    AND {block_instances}.blockname = 'html'
                    AND {block_instances}.pagetypepattern LIKE 'course-%'
                    AND {block_instances}.configdata IS NOT NULL
                    AND {block_instances}.configdata <> ''
                    AND {block_instances}.configdata <> 'Tzo4OiJzdGRDbGFzcyI6MTp7czo0OiJ0ZXh0IjtzOjA6IiI7fQ=='
                    AND {block_instances}.configdata <> 'Tzo2OiJvYmplY3QiOjI6e3M6NToidGl0bGUiO3M6MDoiIjtzOjQ6InRleHQiO3M6MDoiIjt9'");

            $labelcount = $DB->count_records_sql("SELECT COUNT(*)
                    FROM {label}
                    WHERE intro IS NOT NULL
                    AND TRIM(intro) <> ''
                    AND intro NOT REGEXP '^ +$'
                    AND intro NOT REGEXP '<object|<embed|\\.(3gp|aac|aif|asf|avi|flac|flv|m4a|m4p|m4v|mid|mkv|mov|mp3|mp4|mpe|mpeg|mpg|mts|ogg|ogv|ram|rm|swf|wav|wma|wmv)\"'
                    AND intro NOT LIKE '%mso%'
                    AND course = $courseid");

            $linkcount = $DB->count_records_sql("SELECT COUNT(*)
                    FROM {url}
                    WHERE externalurl NOT REGEXP '\\.(3gp|aac|aif|asf|avi|flac|flv|m4a|m4p|m4v|mid|mkv|mov|mp3|mp4|mpe|mpeg|mpg|mts|ogg|ogv|ram|rm|swf|wav|wma|wmv)$'
                    AND externalurl NOT LIKE 'http://www.youtube.com/%'
                    AND course = $courseid");

            $mediacount = $DB->count_records_sql("SELECT labelcount + headingcount + pagecount + chaptercount + filecount AS total
                    FROM (
                        SELECT COUNT(*) AS labelcount
                        FROM {label}
                        WHERE intro REGEXP '<object|<embed|\\.(3gp|aac|aif|asf|avi|flac|flv|m4a|m4p|m4v|mid|mkv|mov|mp3|mp4|mpe|mpeg|mpg|mts|ogg|ogv|ram|rm|swf|wav|wma|wmv)\"'
                        AND intro NOT LIKE '%mso%'
                        AND course = $courseid
                    ) AS labels, (
                        SELECT COUNT(*) AS headingcount
                        FROM {course_sections}
                        WHERE name IS NOT NULL
                        AND TRIM(name) <> ''
                        AND summary REGEXP '<object|<embed|\\.(3gp|aac|aif|asf|avi|flac|flv|m4a|m4p|m4v|mid|mkv|mov|mp3|mp4|mpe|mpeg|mpg|mts|ogg|ogv|ram|rm|swf|wav|wma|wmv)\"'
                        AND summary NOT LIKE '%mso%'
                        AND course = $courseid
                    ) AS headings, (
                        SELECT COUNT(*) AS pagecount
                        FROM {page}
                        WHERE content REGEXP '<object|<embed|\\.(3gp|aac|aif|asf|avi|flac|flv|m4a|m4p|m4v|mid|mkv|mov|mp3|mp4|mpe|mpeg|mpg|mts|ogg|ogv|ram|rm|swf|wav|wma|wmv)\"'
                        AND content NOT LIKE '%mso%'
                        AND course = $courseid
                    ) AS pages, (
                        SELECT COUNT(mdl_book_chapters.id) AS chaptercount
                        FROM {book_chapters}
                        INNER JOIN {book} ON {book_chapters}.bookid = {book}.id
                        WHERE ({book_chapters}.content LIKE '%<object%'
                        OR {book_chapters}.content LIKE '%<embed%')
                        AND {book_chapters}.content NOT LIKE '%mso%'
                        AND {book}.course = $courseid
                    ) AS chapters, (
                        SELECT COUNT({files}.id) AS filecount
                        FROM {files}
                        INNER JOIN {context} ON {files}.contextid = {context}.id
                        INNER JOIN {course_modules} ON {context}.instanceid = {course_modules}.id
                        WHERE {course_modules}.course = $courseid
                        AND {context}.contextlevel = 70
                        AND {files}.component = 'mod_resource'
                        AND {files}.filename REGEXP '\\.(3gp|aac|aif|asf|avi|flac|flv|m4a|m4p|m4v|mid|mkv|mov|mp3|mp4|mpe|mpeg|mpg|mts|ogg|ogv|ram|rm|swf|wav|wma|wmv)$'
                        AND {files}.filename NOT LIKE '.'
                    ) AS files");

            $newscount = $DB->count_records_sql("SELECT COUNT({forum_discussions}.id)
                    FROM {forum_discussions}
                    INNER JOIN {forum} ON {forum_discussions}.forum = {forum}.id
                    WHERE {forum_discussions}.timemodified >= $coursestartdate
                    AND {forum}.type = 'news'
                    AND {forum}.course = $courseid");

            $pagecount = $DB->count_records_sql("SELECT COUNT(*)
                    FROM {page}
                    WHERE content IS NOT NULL
                    AND TRIM(content) <> ''
                    AND content NOT REGEXP '^ +$'
                    AND content NOT REGEXP '<object|<embed|\\.(3gp|aac|aif|asf|avi|flac|flv|m4a|m4p|m4v|mid|mkv|mov|mp3|mp4|mpe|mpeg|mpg|mts|ogg|ogv|ram|rm|swf|wav|wma|wmv)\"'
                    AND content NOT LIKE '%mso%'
                    AND course = $courseid");

            $rsscount = $DB->count_records_sql("SELECT COUNT({block_instances}.id)
                    FROM {block_instances}
                    INNER JOIN {context} ON {block_instances}.parentcontextid = {context}.id
                    WHERE {context}.instanceid = $courseid
                    AND {context}.contextlevel = 50
                    AND {block_instances}.blockname = 'rss_client'
                    AND {block_instances}.pagetypepattern LIKE 'course-%'
                    AND {block_instances}.configdata IS NOT NULL
                    AND {block_instances}.configdata <> ''
                    AND {block_instances}.configdata <> 'Tzo4OiJzdGRDbGFzcyI6NTp7czoxOToiZGlzcGxheV9kZXNjcmlwdGlvbiI7czoxOiIwIjtzOjE0OiJzaG93bnVtZW50cmllcyI7aTo1O3M6NToidGl0bGUiO3M6MDoiIjtzOjM0OiJibG9ja19yc3NfY2xpZW50X3Nob3dfY2hhbm5lbF9saW5rIjtzOjE6IjAiO3M6MzU6ImJsb2NrX3Jzc19jbGllbnRfc2hvd19jaGFubmVsX2ltYWdlIjtzOjE6IjAiO30='
                    AND {block_instances}.configdata <> 'Tzo4OiJzdGRDbGFzcyI6NTp7czoxOToiZGlzcGxheV9kZXNjcmlwdGlvbiI7czoxOiIwIjtzOjE0OiJzaG93bnVtZW50cmllcyI7czoxOiI1IjtzOjU6InRpdGxlIjtzOjA6IiI7czozNDoiYmxvY2tfcnNzX2NsaWVudF9zaG93X2NoYW5uZWxfbGluayI7czoxOiIwIjtzOjM1OiJibG9ja19yc3NfY2xpZW50X3Nob3dfY2hhbm5lbF9pbWFnZSI7czoxOiIwIjt9'
                    AND {block_instances}.configdata <> 'Tzo2OiJvYmplY3QiOjU6e3M6MTk6ImRpc3BsYXlfZGVzY3JpcHRpb24iO3M6MToiMCI7czoxNDoic2hvd251bWVudHJpZXMiO3M6MToiNSI7czo1OiJ0aXRsZSI7czowOiIiO3M6MzQ6ImJsb2NrX3Jzc19jbGllbnRfc2hvd19jaGFubmVsX2xpbmsiO3M6MToiMCI7czozNToiYmxvY2tfcnNzX2NsaWVudF9zaG93X2NoYW5uZWxfaW1hZ2UiO3M6MToiMCI7fQ=='");

            // add to data object
            $rating->booknum     = $bookcount;
            $rating->contentsnum = $contentscount;
            $rating->filenum     = $filecount;
            $rating->foldernum   = $foldercount;
            $rating->gallerynum  = $gallerycount;
            $rating->headingnum  = $headingcount;
            $rating->htmlnum     = $htmlcount;
            $rating->labelnum    = $labelcount;
            $rating->linknum     = $linkcount;
            $rating->medianum    = $mediacount;
            $rating->newsnum     = $newscount;
            $rating->pagenum     = $pagecount;
            $rating->rssnum      = $rsscount;

            // silver stats
            $assignments = $DB->get_recordset('assignment', array('course'=>$courseid), '', 'id');

            // only count assignments with submissions from at least half the students
            $assigncount = 0;

            foreach ($assignments as $assignment) {
                $assignid = $assignment->id;
                $submitcount = $DB->count_records('assignment_submissions', array('assignment'=>$assignid));
                if ($submitcount >= ($enrolcount / 2)) {
                    $assigncount++;
                }
            }
            $assignments->close();

            $choicecount = $DB->count_records('choice', array('course'=>$courseid));

            $hotpotcount = $DB->count_records('hotpot', array('course'=>$courseid));

            $imscpcount = $DB->count_records('imscp', array('course'=>$courseid));

            $nlncount = $DB->count_records('nln', array('course'=>$courseid));

            $questcount = $DB->count_records_sql("SELECT COUNT({questionnaire_question}.id)
                    FROM {questionnaire_question}
                    INNER JOIN {questionnaire_survey}
                    ON {questionnaire_question}.survey_id = {questionnaire_survey}.id
                    WHERE {questionnaire_question}.deleted = 'n'
                    AND {questionnaire_survey}.owner = $courseid");

            $quizcount = $DB->count_records_sql("SELECT COUNT({quiz_question_instances}.id)
                    FROM {quiz_question_instances}
                    INNER JOIN {quiz} ON {quiz_question_instances}.quiz = {quiz}.id
                    WHERE {quiz}.course = $courseid");

            $scormcount = $DB->count_records('scorm', array('course'=>$courseid));

            // add to data object
            $rating->assignnum = $assigncount;
            $rating->choicenum = $choicecount;
            $rating->hotpotnum = $hotpotcount;
            $rating->imscpnum  = $imscpcount;
            $rating->nlnnum    = $nlncount;
            $rating->questnum  = $questcount;
            $rating->quiznum   = $quizcount;
            $rating->scormnum  = $scormcount;

            // gold stats
            $chatcount = $DB->count_records('chat', array('course'=>$courseid));

            $dbcount = $DB->count_records_sql("SELECT COUNT({data_records}.id)
                    FROM {data_records}
                    INNER JOIN {data} ON {data_records}.dataid = {data}.id
                    WHERE {data}.course = $courseid");

            $forumcount = $DB->count_records_sql("SELECT COUNT({forum_discussions}.id)
                    FROM {forum_discussions}
                    INNER JOIN {forum} ON {forum_discussions}.forum = {forum}.id
                    WHERE {forum_discussions}.timemodified >= $coursestartdate
                    AND {forum}.type <> 'news'
                    AND {forum}.course = $courseid");

            $glossarycount = $DB->count_records_sql("SELECT COUNT({glossary_entries}.id)
                    FROM {glossary_entries}
                    INNER JOIN {glossary} ON {glossary_entries}.glossaryid = {glossary}.id
                    WHERE {glossary}.course = $courseid");

            $wikicount = $DB->count_records_sql("SELECT COUNT(DISTINCT({wiki_pages}.title))
                    FROM {wiki_pages}
                    INNER JOIN {wiki_subwikis} ON {wiki_pages}.subwikiid = {wiki_subwikis}.id
                    INNER JOIN {wiki} ON {wiki_subwikis}.wikiid = {wiki}.id
                    WHERE {wiki_pages}.title NOT LIKE 'internal://%'
                    AND {wiki}.course = $courseid");

            // add to data object
            $rating->chatnum     = $chatcount;
            $rating->dbnum       = $dbcount;
            $rating->forumnum    = $forumcount;
            $rating->glossarynum = $glossarycount;
            $rating->wikinum     = $wikicount;

            // if course has enrolments and at least half are active, calculate rating
            if ($enrolcount > 0 && $activepc >= 50) {

                // apply bronze weightings
                $bronzescore = ($bookcount * 2) + ($contentscount * 3) + ($filecount * 1) +
                        ($foldercount * 1) + ($gallerycount * 5) + ($headingcount * 1) +
                        ($htmlcount * 2) + ($labelcount * 1) + ($linkcount * 1) +
                        ($mediacount * 5) + ($newscount * 2) + ($pagecount * 2) +
                        ($rsscount * 3);

                // do we satisfy the minimum criteria for bronze?
                if ($bronzescore >= 30) {
                    $bronze = 1;
                } else {
                    $bronze = 0;
                }

                // add bronze score to data object
                $rating->bscore = $bronzescore;

                // apply silver weightings
                $silverscore = ($assigncount * 10) + ($choicecount * 5) + ($hotpotcount * 10) +
                        ($imscpcount * 5) + ($nlncount * 5) + ($questcount * 1) +
                        ($quizcount * 2) + ($scormcount * 10);

                // do we satisfy the minimum criteria for silver?
                if ($silverscore >= 30) {
                    $silver = 10;
                } else {
                    $silver = 0;
                }

                // add silver score to data object
                $rating->sscore = $silverscore;

                // apply gold weightings
                $goldscore = ($chatcount * 5) + ($dbcount * 5) + ($forumcount * 5) +
                        ($glossarycount * 5) + ($wikicount * 5);

                // do we satisfy the minimum criteria for gold?
                if ($goldscore >= 60) {
                    $gold = 100;
                } else {
                    $gold = 0;
                }

                // add gold score to data object
                $rating->gscore = $goldscore;

                // calculate automatic rating, allowing 'consolation prizes' for
                // courses which satisfy only the higher level criteria
                $aggregate = $bronze + $silver + $gold;

                switch ($aggregate) {
                    case 1:
                        $autorating = 'Bronze';
                        break;
                    case 10:
                        $autorating = 'Bronze';
                        break;
                    case 100:
                        $autorating = 'Bronze';
                        break;
                    case 11:
                        $autorating = 'Silver';
                        break;
                    case 101:
                        $autorating = 'Silver';
                        break;
                    case 110:
                        $autorating = 'Silver';
                        break;
                    case 111:
                        $autorating = 'Gold';
                        break;
                    default:
                        $autorating = ''; // in development
                }

            } else {
                $autorating = ''; // if not enough active enrolments, in development
            }

            // add automatic rating to data object and update course row
            $rating->rating = $autorating;
            $DB->update_record('block_course_rating', $rating);

            echo '<tr>
                      <td><a target="_blank" title="Click to enter this course" href="' . $CFG->wwwroot .
                           '/course/view.php?id=' . $courseid . '">' . $coursefullname . '</a></td>
                      <td>' . $oldrating . '</td>
                      <td>' . $autorating . '</td>
                      <td>
                          <select size="1" name="rating[' . $courseid . '][override]">
                              <option></option>
                              <option value="Gold"';
                              if ($oldrating == 'Gold' && $autorating != 'Gold' && $override == 1) {
                                  echo ' selected';
                              }
                              echo '>Gold</option>
                              <option value="Silver"';
                              if ($oldrating == 'Silver' && $autorating != 'Silver' && $override == 1) {
                                  echo ' selected';
                              }
                              echo '>Silver</option>
                              <option value="Bronze"';
                              if ($oldrating == 'Bronze' && $autorating != 'Bronze' && $override == 1) {
                                  echo ' selected';
                              }
                              echo '>Bronze</option>
                              <option value="In Dev"';
                              if ($oldrating == '' && $autorating != '' && $override == 1) {
                                  echo ' selected';
                              }
                              echo '>In Dev</option>
                              <option value="Exclude"';
                              if ($oldrating == 'Exclude' && $override == 1) {
                                  echo ' selected';
                              }
                              echo '>Exclude</option>
                          </select>
                      </td>
                  </tr>
                  <input type="hidden" name="rating[' . $courseid . '][current]" value="' . $autorating . '">
                  <input type="hidden" name="courseid" value="' . $courseid . '">
                  <input type="hidden" name="catid" value="' . $subcatid . '">';
        }
        $coursedetails->close(); // close recordset, free up resources
        echo '</table>';
    }

}
$subcats->close();

if ($novalidcourses) {
    echo '</form>
          <strong>' . get_string('novalidcourses', 'report_courseaudit', $catname) . '</strong>';
} else {
    echo '<input type="hidden" name="category" value="' . $catname . '">
          <input type="hidden" name="categoryid" value="' . $cat . '"><br />
          <input type="submit" name="submit" value="Process ratings">
          </form>';
}

echo $OUTPUT->footer();