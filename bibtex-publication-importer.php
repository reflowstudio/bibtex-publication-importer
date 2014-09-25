<?php
/*
Plugin Name: BibTeX Publication Importer
Plugin URI: https://github.com/reflowstudio/bibtex-publication-importer
Description: Import publications from the BibTeX scholarly reference format.
Author: Reflow Studio
Author URI: http://reflowstudio.com
Version: 1.0
Stable tag: 1.0

GNU General Public License, Free Software Foundation <http://creativecommons.org/licenses/GPL/2.0/>
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

This code was originally based on the BibTeX Importer Plugin: http://wordpress.org/extend/plugins/bibtex-importer/

This code uses the bibtexParse library from the Bibliophile project: http://bibliophile.sourceforge.net/

*/

if ( !defined('WP_LOAD_IMPORTERS') )
	return;

// Load Importer API
require_once ABSPATH . 'wp-admin/includes/import.php';

// Load bibTexParse
require_once dirname(__FILE__) . '/bibtexparse/parseentries.php';
require_once dirname(__FILE__) . '/bibtexparse/parsecreators.php';

if ( !class_exists( 'WP_Importer' ) ) {
	$class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
	if ( file_exists( $class_wp_importer ) )
		require_once $class_wp_importer;
}

/** Load WordPress Administration Bootstrap */
$parent_file = 'tools.php';
$submenu_file = 'import.php';
$title = __('Import BibTeX Publications', 'bibtex-publication-importer');

/**
 * BibTeX Publication Importer
 *
 * @package WordPress
 * @subpackage Importer
 */
if ( class_exists( 'WP_Importer' ) ) {
class BibTeX_Publication_Import extends WP_Importer {

  function fix_doi_link($doi) {
    return ($doi != "") ? 'http://dx.doi.org/' . $doi : "";
  }

  function utf8($num) {
    if($num<=0x7F)       return chr($num);
    if($num<=0x7FF)      return chr(($num>>6)+192).chr(($num&63)+128);
    if($num<=0xFFFF)     return chr(($num>>12)+224).chr((($num>>6)&63)+128).chr(($num&63)+128);
    if($num<=0x1FFFFF)   return chr(($num>>18)+240).chr((($num>>12)&63)+128).chr((($num>>6)&63)+128).chr(($num&63)+128);
    return '';
  }

  function latex_to_utf8_chars($string) {
    $l2u = array();
    $l2u_diacritic = array(
      '`'=>0x0300,
      '\''=>0x0301,
      '^'=>0x0302,
      '"'=>0x0308,
      '~'=>0x0303,
      '='=>0x0304,
      '.'=>0x0307,
      'u'=>0x0306,
      'v'=>0x030C,
      'c'=>0x0327,
      'd'=>0x0323,
      'b'=>0x0331
    );
    preg_match_all('/{?\\\\(['.implode('', array_keys($l2u_diacritic)).'])({[^}]*}|.)}?/u', $string, $matches, PREG_SET_ORDER);
    foreach($matches as $r) {
      if(!array_key_exists($r[0], $l2u)) {
        $l2u += array($r[0]=>trim($r[2], '{}\\'). $this->utf8($l2u_diacritic[$r[1]]));
      }
    }
    return str_replace(array_keys($l2u), array_values($l2u), $string);
  }

  function convert_latex_chars(&$entry) {
    foreach ($entry as &$field) {
      if (is_string($field))
        $field = $this->latex_to_utf8_chars($field);
    }
  }

  function get_bibtex_file_content( $bibtex_url ) {
    if ( isset($bibtex_url) && $bibtex_url != '' && $bibtex_url != 'http://' ) {
				$bibtex = wp_remote_fopen($bibtex_url);
	  } else { // try to get the upload file.
				$overrides = array('test_form' => false, 'test_type' => false);
				$_FILES['userfile']['name'];
				$file = wp_handle_upload($_FILES['userfile'], $overrides);

				if ( isset($file['error']) )
					wp_die($file['error']);

				$bibtex_url = $file['file'];
				$bibtex = file_get_contents($bibtex_url);
			}
      return $bibtex;
  }

  function parse_entries($bibtex) {
    // Load bibtexParse parser, parse bibtex into array
		$parse = NEW PARSEENTRIES();
		$parse->loadBibtexString($bibtex);
		$parse->extractEntries();
		list($preamble, $strings, $entries, $undefinedStrings) = $parse->returnArrays();
    foreach ($entries as &$entry) {
      $entry['title'] = trim($entry['title'], '{}');
    }
    return $entries;
  }

  function report($state, $text) {
    switch ($state) {
      case 'success': $state = "color: #6e6e6e;"; break;
      case 'skip':    $state = "color: #6e6e6e;"; break;
      case 'error':   $state = "color: #d81b21;"; break;
      default:        $state = "";
    }
    return sprintf("<strong style=\"{$state}\">{$text}</strong>");
  }

  function validate_common_fields($entry) {
    if ( $entry['title'] == "" )
      return $this->report('error', __("Error importing entry: No title attribute found."));

    if ( $entry['author'] == "" )
      return $this->report('error', __("Error importing '{$entry['title']}': No authors found."));

    if ( $entry['year'] == "" || ! is_numeric($entry['year']) || ! count($entry['year']) == 4 )
      return $this->report('error', __("Error importing '{$entry['title']}': Invalid year."));

    return "";
  }

  function split_authors($author_string) {
    return preg_split("/ and /u", $author_string, 0, PREG_SPLIT_NO_EMPTY);
  }

  function is_initial($segment) {
    return is_string($segment) && strlen($segment) == 1;
  }

  function initial($segment) {
    return strtoupper($segment[0]) . '. ';
  }

  function format_author($author) {
    $segments = preg_split("/(,|\.|~| )/u", $author, 0, PREG_SPLIT_NO_EMPTY);
    $surname = "";
    $initials = "";
    foreach ($segments as &$segment) {
      if ($this->is_initial($segment)) {
        $initials .= "{$segment}. ";
      } elseif (empty($surname)) {
        $surname = trim($segment, '{}');
      } else {
        $initials .= $this->initial($segment);
      }
    }
    return $initials . $surname;
  }

  function authors_valid($authors) {
    foreach ($authors as $author) {
      if (empty($author)) {
        return false;
      }
    }
    return true;
  }

  function parse_authors($author_string) {
    $segments = $this->split_authors($author_string);
    $authors = array();
    foreach ($segments as $segment) {
      array_push($authors, $this->format_author($segment));
    }
    return $this->authors_valid($authors) ? $authors : array();
  }

  function month_index($month) {
    switch (strtolower($month)) {
    case 'jan': return 1;
    case 'feb': return 2;
    case 'mar': return 3;
    case 'apr': return 4;
    case 'may': return 5;
    case 'jun': return 6;
    case 'jul': return 7;
    case 'aug': return 8;
    case 'sep': return 9;
    case 'oct': return 10;
    case 'nov': return 11;
    case 'dec': return 12;
    }
    return null;
  }

	function parse_date($fields) {
    $year = $fields['year'];
    $month = '01';
    $day = '01';
    if (! is_null($fields['month'])) {
      $month = $fields['month'];
      $day = '02';
    }
    $datetime = new DateTime("{$month}/{$day}/{$year}");
    return $datetime->format("Y-m-d H:i:s");
	}

  function lookup_journal($abbreviation) {
    if (empty($abbreviation))
      return "";

    $journals = wp_cache_get('bibtex_publication_importer_journals');
    if (! $journals) {
      global $wpdb;
      $raw_field = $wpdb->get_var("SELECT meta_value FROM wp_postmeta WHERE meta_key = ".
                                  "(SELECT DISTINCT meta_value FROM wp_postmeta WHERE meta_key = '_journal')"
                                  , 0, 0);
      $field = unserialize($raw_field);
      $journals = array_map('strtoupper', $field['choices']);
      wp_cache_set('bibtex_publication_importer_journals', $journals);
    }
    $search_term = strtoupper(str_replace(array('.', ','), '', $abbreviation));
    $keys = array_keys($journals);
    if (in_array($search_term, $keys)) {
      $journal = $search_term;
    } elseif ($journal = array_search($search_term, $journals)) {
      // Pass
    } else {
      $journal = "";
    }
    return $journal;
  }

  function fields_for_type($bibtex_type, $entry) {
    $fields = $this->report('error', __("Error importing of '{$entry['title']}': " .
                                        "BibTeX type '@{$bibtex_type}' is not supported."));
    // Note: Some fields are not in the BibTeX spec (marked NIS),
    //       but if they are present, we might as well use them.
    switch ($bibtex_type) {
    case 'article':
      $fields = array(
        'type' => 'paper',
        'journal' => $this->lookup_journal($entry['journal']),
        'volume' => $entry['volume'],
        'issue' => $entry['issue'],
        'page' => $entry['pages'],
        'month' => $this->month_index($entry['month']),  // NIS
        'year' => $entry['year'],
        'url' => $entry['url'],      // NIS
        'doi' => $this->fix_doi_link($entry['doi']),
        'url2' => $entry['url2']     // NIS
      );
      if ($fields['journal'] == "")
        $fields = $this->report('error', __("Error importing '{$entry['title']}': " .
                                            "Journal '{$entry['journal']}' does not exist in the system. " .
                                            "Please ask your administrator to add it and re-import."));
      break;
    case 'book':
      $fields = array(
        'type' => 'book',
        'book' => $entry['title'],
        'month' => $this->month_index($entry['month']),
        'year' => $entry['year'],
        'url' => $entry['url'],      // NIS
        'doi' => $this->fix_doi_link($entry['doi']),
        'ISBN' => $entry['isbn'],
        'url2' => $entry['url2']     // NIS
      );
      break;
    }
    return $fields;
  }

  function post_exists($title) {
    global $wpdb;
    $name = sanitize_title($title);
    return $wpdb->get_var("SELECT ID FROM wp_posts WHERE post_name = '{$name}'", 0, 0);
  }

  function link_to($name, $post_id) {
    return "<a href=\"" . get_edit_post_link($post_id). "\" onclick=\"window.open(this.href); return false;\">{$name}</a>";
  }

  function add_publication($entry) {
    $result_message = "";
    $this->convert_latex_chars($entry);

    if ($existing_post = $this->post_exists($entry['title']))
      return $this->report('skip', __("Skipped importing of '{$this->link_to($entry['title'], $existing_post)}': " .
                                      "Publication already exists."));

    $authors = $this->parse_authors($entry['author']);
    if (empty($authors))
      return $this->report('error', __("Error importing '{$entry['title']}': Invalid author string. " .
                                       "Acceptable formats are 'A. Author' and 'Author, A.'"));
    $bibtex_type = $entry['bibtexEntryType'];
    $fields = $this->fields_for_type($bibtex_type, $entry);

    if (is_array($fields)) {
      $date = $this->parse_date($fields);
      $new_publication = array(
        'post_title' => $entry['title'],
        'post_type' => 'publication',
        'post_status' => 'publish',
        'post_date' => $date,
        'post_date_gmt' => $date,
        'tax_input' => array('authors' => $authors)
      );
      $post_id = wp_insert_post($new_publication);
      if ($post_id != 0) {
        foreach ($fields as $field_key => $field_value) {
          add_post_meta($post_id, $field_key, $field_value);
        }
        $result_message = $this->report('success', __("Imported '{$this->link_to($entry['title'], $post_id)}'."));
      } else {
        $result_message = $this->report('error', __("Error importing '{$entry['title']}': Database insert failed."));
      }
    } else {
      $result_message = $fields;
    }
    return $result_message;
  }

  function import_entry($entry) {
    $result_message = $this->validate_common_fields($entry);
    if ($result_message == "")
      $result_message = $this->add_publication($entry);
    return $result_message;
  }

	function dispatch() {
		global $wpdb, $user_ID;
	  $step = isset( $_POST['step'] ) ? $_POST['step'] : 0;

	  switch ($step) {
		  case 0: {
			  include_once( ABSPATH . 'wp-admin/admin-header.php' );
			  if ( !current_user_can('import') )
				  wp_die(__('You do not have import permissions.', 'bibtex-publication-importer'));

        // Load custom fields api
        if (! function_exists('acf')) {
          wp_die(__('BibTeX import requires the advanced custom fields plugin to be installed and activated.'),
                 'bibtex-publication-importer');
	}
	      ?>

				<div class="wrap">
				  <?php screen_icon(); ?>
				  <h2><?php _e('Import BibTeX references', 'bibtex-publication-importer') ?></h2>
				  <form enctype="multipart/form-data" action="admin.php?import=bibtex_publications" method="post" name="bibtex_publications">
				  <?php wp_nonce_field('import-bookmarks') ?>

				  <p><?php _e('If a program or website you use allows you to export your references as BibTeX you may import them here.', 'bibtex-publication-importer'); ?></p>
				  <div style="width: 90%; margin: auto; height: 8em;">
				    <input type="hidden" name="step" value="1" />
				    <input type="hidden" name="MAX_FILE_SIZE" value="<?php echo wp_max_upload_size(); ?>" />
				    <div style="width: 48%;" class="alignleft">
				    <h3><label for="bibtex_url"><?php _e('Specify a BibTeX URL:', 'bibtex-publication-importer'); ?></label></h3>
				    <input type="text" name="bibtex_url" id="bibtex_url" size="50" class="code" style="width: 90%;" value="http://" />
				  </div>

				  <div style="width: 48%;" class="alignleft">
				    <h3><label for="userfile"><?php _e('Or choose from your local disk:', 'bibtex-publication-importer'); ?></label></h3>
				    <input id="userfile" name="userfile" type="file" size="30" />
				  </div>

				</div>

				<p style="clear: both; margin-top: 1em;">
          <label for="cat_id">
            <?php _e('The importer will automatically create publications for each BibTeX entry and display them on the relevant authors\' pages.', 'bibtex-publication-importer') ?>
          </label>
				</p>

				<p class="submit"><input type="submit" name="submit" value="<?php esc_attr_e('Import BibTeX File', 'bibtex-publication-importer') ?>" /></p>
				</form>

				</div>
				<?php
			break;
		} // end case 0

		case 1: {
			check_admin_referer('import-bookmarks');

			include_once( ABSPATH . 'wp-admin/admin-header.php' );
			if ( !current_user_can('import') )
				wp_die(__('You do not have import permissions.', 'bibtex-publication-importer'));
	?>
	<div class="wrap">

	<h2><?php _e('Importing...', 'bibtex-publication-importer') ?></h2>
	<?php
			$bibtex_url = $_POST['bibtex_url'];
      $bibtex = $this->get_bibtex_file_content($bibtex_url);
      $entries = $this->parse_entries($bibtex);
			if ( ! empty($entries) ) {
				foreach ($entries as $key => $entry) {
					printf('<p>');
          echo $this->import_entry($entry);
          printf('</p>');
        }
        printf('<p>' . $this->report('success', __('Import complete.')) . '</p>');
	    } // end if got url
	    else
	    {
		    echo "<p>" . __("No valid BibTeX file was supplied. Please press back on your browser and try again", 'bibtex-publication-importer') . "</p>\n";
	    } // end else

      do_action( 'wp_delete_file', $bibtex_url);
	    @unlink($bibtex_url);
	?>
	</div>
	<?php
			break;
		} // end case 1
	} // end switch
	}

	function BibTeX_Publication_Import() {}
	}

	$bibtex_importer = new BibTeX_Publication_Import();

	register_importer('bibtex_publications', __('BibTeX Publications', 'bibtex-publication-importer'), __('Import Publications in BibTeX format.', 'bibtex-publication-importer'), array(&$bibtex_importer, 'dispatch'));

}
