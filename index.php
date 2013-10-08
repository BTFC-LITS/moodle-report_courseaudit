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
echo $OUTPUT->header();

if ($_POST) {
    $submitted = $_POST['submit'];
    $category = $_POST['category'];
}

$moodle_title = $GLOBALS['SITE']->fullname;

if (isset($submitted)) {
    $categoryid = $_POST['categoryid'];
    echo "<h3>Course ratings have been processed for
        <a href='#$categoryid' title='Go to audit summary for $category'>$category</a></h3>";

    // get sub-categories, excluding ones we aren't interested in
    $subcats = $DB->get_recordset_sql("SELECT id FROM {course_categories}
            WHERE (path LIKE '/$categoryid/%' OR id = $categoryid)
            AND name NOT LIKE '%child courses%'
            AND name NOT LIKE '%staff only%'
            AND name NOT LIKE '%sandbox%'
            AND name NOT LIKE '%archive%'
            ORDER BY path");

    // get course ids from current and sub-categories to update ratings
    foreach ($subcats as $subcat) {
        $subcatid = $subcat->id;
        $courses = $DB->get_recordset('course', array('category'=>$subcatid, 'visible'=>1),
                'sortorder ASC', 'id');

        // loop through courses and process rating overrides
        foreach ($courses as $course) {
            $courseid = $course->id;
            $current = $_POST['rating'][$courseid]['current'];
            $override = $_POST['rating'][$courseid]['override'];
            $overrideflag = 0;

            if ($override != "") {
                $overrideflag = 1;
                if ($override == "In Dev") {
                    $finalrating = "";
                } else {
                    $finalrating = $override;
                }
            } else {
                $finalrating = $current;
            }

            // create data object and update rating
            $rating = new stdClass;
            $rating->id       = $DB->get_field('block_course_rating', 'id', array('courseid'=>$courseid));
            $rating->rating   = $finalrating;
            $rating->override = $overrideflag;
            $DB->update_record('block_course_rating', $rating);

        }
        $courses->close();
    }
    $subcats->close();
}

// select category to process
echo "<p>Use the drop down box below to select the Moodle category you'd like to process, then press submit.</p>";

// get details of top level course categories
$cats = $DB->get_recordset_sql("SELECT id, name
        FROM {course_categories}
        WHERE depth = 1
        OR name LIKE 'taster courses'
        ORDER BY name ASC");

echo "<form method='get' action='$CFG->wwwroot/report/courseaudit/auditbycat.php'>";
echo "<div>";
echo "<select size='1' name='cat'>";
echo "<option selected='selected'>Select...</option>";

// loop through and list category names in drop down box
foreach ($cats as $cat) {
    $catid = $cat->id;
    $catname = $cat->name;
    echo "<option value='$catid'>$catname</option>";
}
$cats->close();

echo "</select>";
echo "<input type='submit' value='Submit' />";
echo "</div>";
echo "</form>";

// audit summary table
$goldtotal = $DB->count_records('block_course_rating', array('rating'=>'gold'));
$silvertotal = $DB->count_records('block_course_rating', array('rating'=>'silver'));
$bronzetotal = $DB->count_records('block_course_rating', array('rating'=>'bronze'));
$excludetotal = $DB->count_records('block_course_rating', array('rating'=>'exclude'));

$coursetotal = $DB->count_records_sql("SELECT COUNT({course}.id)
        FROM {course} INNER JOIN {course_categories} ON {course}.category = {course_categories}.id
        WHERE {course_categories}.name NOT LIKE '%child courses%'
        AND {course_categories}.name NOT LIKE '%staff only%'
        AND {course_categories}.name NOT LIKE '%sandbox%'
        AND {course_categories}.name NOT LIKE '%archive%'
        AND {course}.visible = 1");

$coursetotal = $coursetotal - $excludetotal;
$ratingstotal = $goldtotal + $silvertotal + $bronzetotal;
$indevtotal = $coursetotal - $ratingstotal;

// format as percentages of total courses
$goldpc = sprintf('%01.1f', ($goldtotal / $coursetotal * 100));
$silverpc = sprintf('%01.1f', ($silvertotal / $coursetotal * 100));
$bronzepc = sprintf('%01.1f', ($bronzetotal / $coursetotal * 100));
$indevpc = sprintf('%01.1f', ($indevtotal / $coursetotal * 100));

$table = "<table style='text-align: left; width: 100%;' border='0' cellpadding='2' cellspacing='2'>
              <tbody>
                  <tr>
                      <td style='width: 10%;'></td>
                      <td style='width: 80%;'>
                          <div class='generalbox' align='center'>
                              <table width='100%' border='0' cellspacing='0' cellpadding='0'>
                                  <tr>
                                      <td width='100%' colspan='4'>
                                          <h3 align='center'>Course audit summary for $moodle_title</h3>
                                      </td>
                                  </tr>
                                  <tr>
                                      <td width='25%'>
                                          <div align='right'>
                                              <img src='$CFG->wwwroot/blocks/course_rating/pix/gold_icon.png' alt='Gold' />
                                          </div>
                                      </td>
                                      <td width='25%'>
                                          <div align='left'>&nbsp;&nbsp;&nbsp;Gold</div>
                                      </td>
                                      <td width='20%'>
                                          <div align='left'>" . $goldtotal . "</div>
                                      </td>
                                      <td width='30%'>
                                          <div align='left'>" . $goldpc . "%</div>
                                      </td>
                                  </tr>
                                  <tr>
                                      <td width='25%'>
                                          <div align='right'>
                                              <img src='$CFG->wwwroot/blocks/course_rating/pix/silver_icon.png' alt='Silver' />
                                          </div>
                                      </td>
                                      <td width='25%'>
                                          <div align='left'>&nbsp;&nbsp;&nbsp;Silver</div>
                                      </td>
                                      <td width='20%'>
                                          <div align='left'>" . $silvertotal . "</div>
                                      </td>
                                      <td width='30%'>
                                          <div align='left'>" . $silverpc . "%</div>
                                      </td>
                                  </tr>
                                  <tr>
                                      <td width='25%'>
                                          <div align='right'>
                                              <img src='$CFG->wwwroot/blocks/course_rating/pix/bronze_icon.png' alt='Bronze' />
                                          </div>
                                      </td>
                                      <td width='25%'>
                                          <div align='left'>&nbsp;&nbsp;&nbsp;Bronze</div>
                                      </td>
                                      <td width='20%'>
                                          <div align='left'>" . $bronzetotal . "</div>
                                      </td>
                                      <td width='30%'>
                                          <div align='left'>" . $bronzepc . "%</div>
                                      </td>
                                  </tr>
                                  <tr>
                                      <td width='25%' height='26'>&nbsp;</td>
                                      <td width='25%'>
                                          <div align='left'>&nbsp;&nbsp;&nbsp;In Dev</div>
                                      </td>
                                      <td width='20%'>
                                          <div align='left'>" . $indevtotal . "</div>
                                      </td>
                                      <td width='30%'>
                                          <div align='left'>" . $indevpc . "%</div>
                                      </td>
                                  </tr>
                              </table>
                              <hr width='60%' />
                              <table width='100%' border='0' cellpadding='0' cellspacing='0'>
                                  <tr>
                                      <td width='25%'><div align='left'>&nbsp;</div></td>
                                      <td width='25%'><div align='left'>Total courses</div></td>
                                      <td width='20%'><div align='left'>" . $coursetotal . "</div></td>
                                      <td width='30%'><div align='left'>&nbsp;</div></td>
                                  </tr>
                              </table>
                              <hr width='60%' />
                          </div>
                      </td>
                      <td style='width: 10%;'></td>
                  </tr>
              </tbody>
          </table>";

echo $table;

// audit summary table per each top level category
$cats = $DB->get_recordset_sql('SELECT id, name FROM {course_categories} WHERE depth = 1 ORDER BY name ASC');

foreach ($cats as $cat) {
    $catid = $cat->id;
    $catname = $cat->name;
    $subcats = $DB->get_recordset_sql("SELECT id FROM {course_categories}
            WHERE (path LIKE '/$catid/%' OR id = $catid)
            AND name NOT LIKE '%child courses%'
            AND name NOT LIKE '%staff only%'
            AND name NOT LIKE '%sandbox%'
            AND name NOT LIKE '%archive%'
            ORDER BY path");

    // skip category if it contains no valid sub-categories
    if (!$subcats->valid()) {
        continue;
    }

    $subcatids = "";

    foreach ($subcats as $subcat) {
        $subcatid = $subcat->id;
        $subcatids .= $subcatid . ",";
    }
    $subcats->close();

    $subcatids = substr($subcatids, 0, -1);

    $goldcount = $DB->count_records_sql("SELECT COUNT({block_course_rating}.rating)
            FROM {block_course_rating}
            INNER JOIN {course} ON {block_course_rating}.courseid = {course}.id
            WHERE {block_course_rating}.rating = 'Gold'
            AND {course}.category IN ($subcatids)");

    $silvercount = $DB->count_records_sql("SELECT COUNT({block_course_rating}.rating)
            FROM {block_course_rating}
            INNER JOIN {course} ON {block_course_rating}.courseid = {course}.id
            WHERE {block_course_rating}.rating = 'Silver'
            AND {course}.category IN ($subcatids)");

    $bronzecount = $DB->count_records_sql("SELECT COUNT({block_course_rating}.rating)
            FROM {block_course_rating}
            INNER JOIN {course} ON {block_course_rating}.courseid = {course}.id
            WHERE {block_course_rating}.rating = 'Bronze'
            AND {course}.category IN ($subcatids)");

    $excludecount = $DB->count_records_sql("SELECT COUNT({block_course_rating}.rating)
            FROM {block_course_rating}
            INNER JOIN {course} ON {block_course_rating}.courseid = {course}.id
            WHERE {block_course_rating}.rating = 'Exclude'
            AND {course}.category IN ($subcatids)");

    $coursecount = $DB->count_records_sql("SELECT COUNT({course}.id)
            FROM {course}
            INNER JOIN {course_categories} ON {course}.category = {course_categories}.id
            WHERE {course}.category IN ($subcatids)
            AND {course_categories}.name NOT LIKE '%child courses%'
            AND {course_categories}.name NOT LIKE '%staff only%'
            AND {course_categories}.name NOT LIKE '%sandbox%'
            AND {course_categories}.name NOT LIKE '%archive%'
            AND {course}.visible = 1");

    if ($coursecount > 0) {
        $coursecount = $coursecount - $excludecount;
        $ratingscount = $goldcount + $silvercount + $bronzecount;
        $indevcount = $coursecount - $ratingscount;

        // format as percentages
        $goldpc = sprintf('%01.1f', ($goldcount / $coursecount * 100));
        $silverpc = sprintf('%01.1f', ($silvercount / $coursecount * 100));
        $bronzepc = sprintf('%01.1f', ($bronzecount / $coursecount * 100));
        $indevpc = sprintf('%01.1f', ($indevcount / $coursecount * 100));

        $table = "<table style='text-align: left; width: 100%;' border='0' cellpadding='2' cellspacing='2'>
                      <tbody>
                          <tr>
                              <td style='width: 10%;'></td>
                              <td style='width: 80%;'>
                                  <div class='generalbox' align='center'>
                                      <table width='100%' border='0' cellspacing='0' cellpadding='0'>
                                          <tr>
                                              <td width='100%' colspan='4'>
                                                  <h3 align='center'>Course audit summary for
                                                      <a href='$CFG->wwwroot/report/courseaudit/auditbycat.php?cat=$catid'
                                                          title='Update course ratings' name='$catid'>$catname</a>
                                                  </h3>
                                              </td>
                                          </tr>
                                          <tr>
                                              <td width='25%'>
                                                  <div align='right'>
                                                      <img src='$CFG->wwwroot/blocks/course_rating/pix/gold_icon.png' alt='Gold' />
                                                  </div>
                                              </td>
                                              <td width='25%'>
                                                  <div align='left'>&nbsp;&nbsp;&nbsp;Gold</div>
                                              </td>
                                              <td width='20%'>
                                                  <div align='left'>" . $goldcount . "</div>
                                              </td>
                                              <td width='30%'>
                                                  <div align='left'>" . $goldpc . "%</div>
                                              </td>
                                          </tr>
                                          <tr>
                                              <td width='25%'>
                                                  <div align='right'>
                                                      <img src='$CFG->wwwroot/blocks/course_rating/pix/silver_icon.png' alt='Silver' />
                                                  </div>
                                              </td>
                                              <td width='25%'>
                                                  <div align='left'>&nbsp;&nbsp;&nbsp;Silver</div>
                                              </td>
                                              <td width='20%'>
                                                  <div align='left'>" . $silvercount . "</div>
                                              </td>
                                              <td width='30%'>
                                                  <div align='left'>" . $silverpc . "%</div>
                                              </td>
                                          </tr>
                                          <tr>
                                              <td width='25%'>
                                                  <div align='right'>
                                                      <img src='$CFG->wwwroot/blocks/course_rating/pix/bronze_icon.png' alt='Bronze' />
                                                  </div>
                                              </td>
                                              <td width='25%'>
                                                  <div align='left'>&nbsp;&nbsp;&nbsp;Bronze</div>
                                              </td>
                                              <td width='20%'>
                                                  <div align='left'>" . $bronzecount . "</div>
                                              </td>
                                              <td width='30%'>
                                                  <div align='left'>" . $bronzepc . "%</div>
                                              </td>
                                          </tr>
                                          <tr>
                                              <td width='25%' height='26'>&nbsp;</td>
                                              <td width='25%'>
                                                  <div align='left'>&nbsp;&nbsp;&nbsp;In Dev</div>
                                              </td>
                                              <td width='20%'>
                                                  <div align='left'>" . $indevcount . "</div>
                                              </td>
                                              <td width='30%'>
                                                  <div align='left'>" . $indevpc . "%</div>
                                              </td>
                                          </tr>
                                      </table>
                                      <hr width='60%' />
                                      <table width='100%' border='0' cellpadding='0' cellspacing='0'>
                                          <tr>
                                              <td width='25%'><div align='left'>&nbsp;</div></td>
                                              <td width='25%'><div align='left'>Total courses</div></td>
                                              <td width='20%'><div align='left'>" . $coursecount . "</div></td>
                                              <td width='30%'><div align='left'>&nbsp;</div></td>
                                          </tr>
                                      </table>
                                      <hr width='60%' />
                                  </div>
                              </td>
                              <td style='width: 10%;'></td>
                          </tr>
                      </tbody>
                  </table>";

        echo $table;
    }

}
$cats->close();

echo "<p><a href='$CFG->wwwroot/report/courseaudit/download.php'>Download audit
        report as a spreadsheet</a></p>";
echo "<p style='font-size:0.8em;'>Note: you should process the course ratings for
        each School before attempting to download the report.</p>";
echo "<hr /><p><a href='$CFG->wwwroot/report/courseaudit/downloadtaster.php'>Download
        audit report for taster courses</a></p>";

echo $OUTPUT->footer();