<?php
/**
 * Hold the PhpMyAdmin\Util class
 *
 * @package PhpMyAdmin
 */
declare(strict_types=1);

namespace PhpMyAdmin;

use Closure;
use PhpMyAdmin\Html\Generator;
use PhpMyAdmin\Html\MySQLDocumentation;
use PhpMyAdmin\SqlParser\Context;
use PhpMyAdmin\SqlParser\Token;
use phpseclib\Crypt\Random;
use stdClass;

/**
 * Misc functions used all over the scripts.
 *
 * @package PhpMyAdmin
 */
class Util
{
    /**
     * Checks whether configuration value tells to show icons.
     *
     * @param string $value Configuration option name
     *
     * @return boolean Whether to show icons.
     */
    public static function showIcons($value)
    {
        return in_array($GLOBALS['cfg'][$value], ['icons', 'both']);
    }

    /**
     * Checks whether configuration value tells to show text.
     *
     * @param string $value Configuration option name
     *
     * @return boolean Whether to show text.
     */
    public static function showText($value)
    {
        return in_array($GLOBALS['cfg'][$value], ['text', 'both']);
    }

    /**
     * Returns the formatted maximum size for an upload
     *
     * @param integer $max_upload_size the size
     *
     * @return string the message
     *
     * @access  public
     */
    public static function getFormattedMaximumUploadSize($max_upload_size)
    {
        // I have to reduce the second parameter (sensitiveness) from 6 to 4
        // to avoid weird results like 512 kKib
        [$max_size, $max_unit] = self::formatByteDown($max_upload_size, 4);
        return '(' . sprintf(__('Max: %s%s'), $max_size, $max_unit) . ')';
    }

    /**
     * Add slashes before "_" and "%" characters for using them in MySQL
     * database, table and field names.
     * Note: This function does not escape backslashes!
     *
     * @param string $name the string to escape
     *
     * @return string the escaped string
     *
     * @access  public
     */
    public static function escapeMysqlWildcards($name)
    {
        return strtr($name, ['_' => '\\_', '%' => '\\%']);
    } // end of the 'escapeMysqlWildcards()' function

    /**
     * removes slashes before "_" and "%" characters
     * Note: This function does not unescape backslashes!
     *
     * @param string $name the string to escape
     *
     * @return string   the escaped string
     *
     * @access  public
     */
    public static function unescapeMysqlWildcards($name)
    {
        return strtr($name, ['\\_' => '_', '\\%' => '%']);
    } // end of the 'unescapeMysqlWildcards()' function

    /**
     * removes quotes (',",`) from a quoted string
     *
     * checks if the string is quoted and removes this quotes
     *
     * @param string $quoted_string string to remove quotes from
     * @param string $quote         type of quote to remove
     *
     * @return string unqoted string
     */
    public static function unQuote($quoted_string, $quote = null)
    {
        $quotes = [];

        if ($quote === null) {
            $quotes[] = '`';
            $quotes[] = '"';
            $quotes[] = "'";
        } else {
            $quotes[] = $quote;
        }

        foreach ($quotes as $quote) {
            if (mb_substr($quoted_string, 0, 1) === $quote
                && mb_substr($quoted_string, -1, 1) === $quote
            ) {
                $unquoted_string = mb_substr($quoted_string, 1, -1);
                // replace escaped quotes
                $unquoted_string = str_replace(
                    $quote . $quote,
                    $quote,
                    $unquoted_string
                );
                return $unquoted_string;
            }
        }

        return $quoted_string;
    }

    /**
     * Get a URL link to the official MySQL documentation
     *
     * @param string $link   contains name of page/anchor that is being linked
     * @param string $anchor anchor to page part
     *
     * @return string  the URL link
     *
     * @access  public
     */
    public static function getMySQLDocuURL($link, $anchor = '')
    {
        // Fixup for newly used names:
        $link = str_replace('_', '-', mb_strtolower($link));

        if (empty($link)) {
            $link = 'index';
        }
        $mysql = '5.5';
        $lang = 'en';
        if (isset($GLOBALS['dbi'])) {
            $serverVersion = $GLOBALS['dbi']->getVersion();
            if ($serverVersion >= 50700) {
                $mysql = '5.7';
            } elseif ($serverVersion >= 50600) {
                $mysql = '5.6';
            } elseif ($serverVersion >= 50500) {
                $mysql = '5.5';
            }
        }
        $url = 'https://dev.mysql.com/doc/refman/'
            . $mysql . '/' . $lang . '/' . $link . '.html';
        if (! empty($anchor)) {
            $url .= '#' . $anchor;
        }

        return Core::linkURL($url);
    }

    /**
     * Check the correct row count
     *
     * @param string $db    the db name
     * @param array  $table the table infos
     *
     * @return int the possibly modified row count
     */
    private static function _checkRowCount($db, array $table)
    {
        $rowCount = 0;

        if ($table['Rows'] === null) {
            // Do not check exact row count here,
            // if row count is invalid possibly the table is defect
            // and this would break the navigation panel;
            // but we can check row count if this is a view or the
            // information_schema database
            // since Table::countRecords() returns a limited row count
            // in this case.

            // set this because Table::countRecords() can use it
            $tbl_is_view = $table['TABLE_TYPE'] == 'VIEW';

            if ($tbl_is_view || $GLOBALS['dbi']->isSystemSchema($db)) {
                $rowCount = $GLOBALS['dbi']
                    ->getTable($db, $table['Name'])
                    ->countRecords();
            }
        }
        return $rowCount;
    }

    /**
     * returns array with tables of given db with extended information and grouped
     *
     * @param string   $db           name of db
     * @param string   $tables       name of tables
     * @param integer  $limit_offset list offset
     * @param int|bool $limit_count  max tables to return
     *
     * @return array    (recursive) grouped table list
     */
    public static function getTableList(
        $db,
        $tables = null,
        $limit_offset = 0,
        $limit_count = false
    ) {
        $sep = $GLOBALS['cfg']['NavigationTreeTableSeparator'];

        if ($tables === null) {
            $tables = $GLOBALS['dbi']->getTablesFull(
                $db,
                '',
                false,
                $limit_offset,
                $limit_count
            );
            if ($GLOBALS['cfg']['NaturalOrder']) {
                uksort($tables, 'strnatcasecmp');
            }
        }

        if (count($tables) < 1) {
            return $tables;
        }

        $default = [
            'Name'      => '',
            'Rows'      => 0,
            'Comment'   => '',
            'disp_name' => '',
        ];

        $table_groups = [];

        foreach ($tables as $table_name => $table) {
            $table['Rows'] = self::_checkRowCount($db, $table);

            // in $group we save the reference to the place in $table_groups
            // where to store the table info
            if ($GLOBALS['cfg']['NavigationTreeEnableGrouping']
                && $sep && mb_strstr($table_name, $sep)
            ) {
                $parts = explode($sep, $table_name);

                $group =& $table_groups;
                $i = 0;
                $group_name_full = '';
                $parts_cnt = count($parts) - 1;

                while (($i < $parts_cnt)
                    && ($i < $GLOBALS['cfg']['NavigationTreeTableLevel'])
                ) {
                    $group_name = $parts[$i] . $sep;
                    $group_name_full .= $group_name;

                    if (! isset($group[$group_name])) {
                        $group[$group_name] = [];
                        $group[$group_name]['is' . $sep . 'group'] = true;
                        $group[$group_name]['tab' . $sep . 'count'] = 1;
                        $group[$group_name]['tab' . $sep . 'group']
                            = $group_name_full;
                    } elseif (! isset($group[$group_name]['is' . $sep . 'group'])) {
                        $table = $group[$group_name];
                        $group[$group_name] = [];
                        $group[$group_name][$group_name] = $table;
                        $group[$group_name]['is' . $sep . 'group'] = true;
                        $group[$group_name]['tab' . $sep . 'count'] = 1;
                        $group[$group_name]['tab' . $sep . 'group']
                            = $group_name_full;
                    } else {
                        $group[$group_name]['tab' . $sep . 'count']++;
                    }

                    $group =& $group[$group_name];
                    $i++;
                }
            } else {
                if (! isset($table_groups[$table_name])) {
                    $table_groups[$table_name] = [];
                }
                $group =& $table_groups;
            }

            $table['disp_name'] = $table['Name'];
            $group[$table_name] = array_merge($default, $table);
        }

        return $table_groups;
    }

    /* ----------------------- Set of misc functions ----------------------- */

    /**
     * Adds backquotes on both sides of a database, table or field name.
     * and escapes backquotes inside the name with another backquote
     *
     * example:
     * <code>
     * echo backquote('owner`s db'); // `owner``s db`
     *
     * </code>
     *
     * @param mixed   $a_name the database, table or field name to "backquote"
     *                        or array of it
     * @param boolean $do_it  a flag to bypass this function (used by dump
     *                        functions)
     *
     * @return mixed    the "backquoted" database, table or field name
     *
     * @access  public
     */
    public static function backquote($a_name, $do_it = true)
    {
        return static::backquoteCompat($a_name, 'NONE', $do_it);
    } // end of the 'backquote()' function

    /**
     * Adds backquotes on both sides of a database, table or field name.
     * in compatibility mode
     *
     * example:
     * <code>
     * echo backquoteCompat('owner`s db'); // `owner``s db`
     *
     * </code>
     *
     * @param mixed   $a_name        the database, table or field name to
     *                               "backquote" or array of it
     * @param string  $compatibility string compatibility mode (used by dump
     *                               functions)
     * @param boolean $do_it         a flag to bypass this function (used by dump
     *                               functions)
     *
     * @return mixed the "backquoted" database, table or field name
     *
     * @access  public
     */
    public static function backquoteCompat(
        $a_name,
        $compatibility = 'MSSQL',
        $do_it = true
    ) {
        if (is_array($a_name)) {
            foreach ($a_name as &$data) {
                $data = self::backquoteCompat($data, $compatibility, $do_it);
            }
            return $a_name;
        }

        if (! $do_it) {
            if (! (Context::isKeyword($a_name) & Token::FLAG_KEYWORD_RESERVED)) {
                return $a_name;
            }
        }

        // @todo add more compatibility cases (ORACLE for example)
        switch ($compatibility) {
            case 'MSSQL':
                $quote = '"';
                $escapeChar = '\\';
                break;
            default:
                $quote = '`';
                $escapeChar = '`';
                break;
        }

        // '0' is also empty for php :-(
        if (strlen((string) $a_name) > 0 && $a_name !== '*') {
            return $quote . str_replace($quote, $escapeChar . $quote, (string) $a_name) . $quote;
        }

        return $a_name;
    } // end of the 'backquoteCompat()' function

    /**
     * Verifies if current MySQL server supports profiling
     *
     * @return boolean whether profiling is supported
     *
     * @access  public
     */
    public static function profilingSupported()
    {
        if (! self::cacheExists('profiling_supported')) {
            // 5.0.37 has profiling but for example, 5.1.20 does not
            // (avoid a trip to the server for MySQL before 5.0.37)
            // and do not set a constant as we might be switching servers
            if ($GLOBALS['dbi']->fetchValue('SELECT @@have_profiling')
            ) {
                self::cacheSet('profiling_supported', true);
            } else {
                self::cacheSet('profiling_supported', false);
            }
        }

        return self::cacheGet('profiling_supported');
    }

    /**
     * Formats $value to byte view
     *
     * @param double|int $value the value to format
     * @param int        $limes the sensitiveness
     * @param int        $comma the number of decimals to retain
     *
     * @return array|null the formatted value and its unit
     *
     * @access  public
     */
    public static function formatByteDown($value, $limes = 6, $comma = 0)
    {
        if ($value === null) {
            return null;
        }

        $byteUnits = [
            /* l10n: shortcuts for Byte */
            __('B'),
            /* l10n: shortcuts for Kilobyte */
            __('KiB'),
            /* l10n: shortcuts for Megabyte */
            __('MiB'),
            /* l10n: shortcuts for Gigabyte */
            __('GiB'),
            /* l10n: shortcuts for Terabyte */
            __('TiB'),
            /* l10n: shortcuts for Petabyte */
            __('PiB'),
            /* l10n: shortcuts for Exabyte */
            __('EiB'),
        ];

        $dh = pow(10, $comma);
        $li = pow(10, $limes);
        $unit = $byteUnits[0];

        for ($d = 6, $ex = 15; $d >= 1; $d--, $ex -= 3) {
            $unitSize = $li * pow(10, $ex);
            if (isset($byteUnits[$d]) && $value >= $unitSize) {
                // use 1024.0 to avoid integer overflow on 64-bit machines
                $value = round($value / (pow(1024, $d) / $dh)) / $dh;
                $unit = $byteUnits[$d];
                break 1;
            } // end if
        } // end for

        if ($unit != $byteUnits[0]) {
            // if the unit is not bytes (as represented in current language)
            // reformat with max length of 5
            // 4th parameter=true means do not reformat if value < 1
            $return_value = self::formatNumber($value, 5, $comma, true, false);
        } else {
            // do not reformat, just handle the locale
            $return_value = self::formatNumber($value, 0);
        }

        return [
            trim($return_value),
            $unit,
        ];
    } // end of the 'formatByteDown' function


    /**
     * Formats $value to the given length and appends SI prefixes
     * with a $length of 0 no truncation occurs, number is only formatted
     * to the current locale
     *
     * examples:
     * <code>
     * echo formatNumber(123456789, 6);     // 123,457 k
     * echo formatNumber(-123456789, 4, 2); //    -123.46 M
     * echo formatNumber(-0.003, 6);        //      -3 m
     * echo formatNumber(0.003, 3, 3);      //       0.003
     * echo formatNumber(0.00003, 3, 2);    //       0.03 m
     * echo formatNumber(0, 6);             //       0
     * </code>
     *
     * @param double  $value          the value to format
     * @param integer $digits_left    number of digits left of the comma
     * @param integer $digits_right   number of digits right of the comma
     * @param boolean $only_down      do not reformat numbers below 1
     * @param boolean $noTrailingZero removes trailing zeros right of the comma
     *                                (default: true)
     *
     * @return string   the formatted value and its unit
     *
     * @access  public
     */
    public static function formatNumber(
        $value,
        $digits_left = 3,
        $digits_right = 0,
        $only_down = false,
        $noTrailingZero = true
    ) {
        if ($value == 0) {
            return '0';
        }

        $originalValue = $value;
        //number_format is not multibyte safe, str_replace is safe
        if ($digits_left === 0) {
            $value = number_format(
                (float) $value,
                $digits_right,
                /* l10n: Decimal separator */
                __('.'),
                /* l10n: Thousands separator */
                __(',')
            );
            if (($originalValue != 0) && (floatval($value) == 0)) {
                $value = ' <' . (1 / pow(10, $digits_right));
            }
            return $value;
        }

        // this units needs no translation, ISO
        $units = [
            -8 => 'y',
            -7 => 'z',
            -6 => 'a',
            -5 => 'f',
            -4 => 'p',
            -3 => 'n',
            -2 => 'µ',
            -1 => 'm',
            0 => ' ',
            1 => 'k',
            2 => 'M',
            3 => 'G',
            4 => 'T',
            5 => 'P',
            6 => 'E',
            7 => 'Z',
            8 => 'Y',
        ];
        /* l10n: Decimal separator */
        $decimal_sep = __('.');
        /* l10n: Thousands separator */
        $thousands_sep = __(',');

        // check for negative value to retain sign
        if ($value < 0) {
            $sign = '-';
            $value = abs($value);
        } else {
            $sign = '';
        }

        $dh = pow(10, $digits_right);

        /*
         * This gives us the right SI prefix already,
         * but $digits_left parameter not incorporated
         */
        $d = floor(log10((float) $value) / 3);
        /*
         * Lowering the SI prefix by 1 gives us an additional 3 zeros
         * So if we have 3,6,9,12.. free digits ($digits_left - $cur_digits)
         * to use, then lower the SI prefix
         */
        $cur_digits = floor(log10($value / pow(1000, $d)) + 1);
        if ($digits_left > $cur_digits) {
            $d -= floor(($digits_left - $cur_digits) / 3);
        }

        if ($d < 0 && $only_down) {
            $d = 0;
        }

        $value = round($value / (pow(1000, $d) / $dh)) / $dh;
        $unit = $units[$d];

        // number_format is not multibyte safe, str_replace is safe
        $formattedValue = number_format(
            $value,
            $digits_right,
            $decimal_sep,
            $thousands_sep
        );
        // If we don't want any zeros, remove them now
        if ($noTrailingZero && strpos($formattedValue, $decimal_sep) !== false) {
            $formattedValue = preg_replace('/' . preg_quote($decimal_sep, '/') . '?0+$/', '', $formattedValue);
        }

        if ($originalValue != 0 && floatval($value) == 0) {
            return ' <' . number_format(
                1 / pow(10, $digits_right),
                $digits_right,
                $decimal_sep,
                $thousands_sep
            )
            . ' ' . $unit;
        }

        return $sign . $formattedValue . ' ' . $unit;
    } // end of the 'formatNumber' function

    /**
     * Returns the number of bytes when a formatted size is given
     *
     * @param string $formatted_size the size expression (for example 8MB)
     *
     * @return integer  The numerical part of the expression (for example 8)
     */
    public static function extractValueFromFormattedSize($formatted_size)
    {
        $return_value = -1;

        $formatted_size = (string) $formatted_size;

        if (preg_match('/^[0-9]+GB$/', $formatted_size)) {
            $return_value = (int) mb_substr(
                $formatted_size,
                0,
                -2
            ) * pow(1024, 3);
        } elseif (preg_match('/^[0-9]+MB$/', $formatted_size)) {
            $return_value = (int) mb_substr(
                $formatted_size,
                0,
                -2
            ) * pow(1024, 2);
        } elseif (preg_match('/^[0-9]+K$/', $formatted_size)) {
            $return_value = (int) mb_substr(
                $formatted_size,
                0,
                -1
            ) * pow(1024, 1);
        }
        return $return_value;
    }

    /**
     * Writes localised date
     *
     * @param integer $timestamp the current timestamp
     * @param string  $format    format
     *
     * @return string   the formatted date
     *
     * @access  public
     */
    public static function localisedDate($timestamp = -1, $format = '')
    {
        $month = [
            /* l10n: Short month name */
            __('Jan'),
            /* l10n: Short month name */
            __('Feb'),
            /* l10n: Short month name */
            __('Mar'),
            /* l10n: Short month name */
            __('Apr'),
            /* l10n: Short month name */
            _pgettext('Short month name', 'May'),
            /* l10n: Short month name */
            __('Jun'),
            /* l10n: Short month name */
            __('Jul'),
            /* l10n: Short month name */
            __('Aug'),
            /* l10n: Short month name */
            __('Sep'),
            /* l10n: Short month name */
            __('Oct'),
            /* l10n: Short month name */
            __('Nov'),
            /* l10n: Short month name */
            __('Dec'),
        ];
        $day_of_week = [
            /* l10n: Short week day name for Sunday */
            _pgettext('Short week day name for Sunday', 'Sun'),
            /* l10n: Short week day name for Monday */
            __('Mon'),
            /* l10n: Short week day name for Tuesday */
            __('Tue'),
            /* l10n: Short week day name for Wednesday */
            __('Wed'),
            /* l10n: Short week day name for Thursday */
            __('Thu'),
            /* l10n: Short week day name for Friday */
            __('Fri'),
            /* l10n: Short week day name for Saturday */
            __('Sat'),
        ];

        if ($format == '') {
            /* l10n: See https://www.php.net/manual/en/function.strftime.php */
            $format = __('%B %d, %Y at %I:%M %p');
        }

        if ($timestamp == -1) {
            $timestamp = time();
        }

        $date = preg_replace(
            '@%[aA]@',
            $day_of_week[(int) strftime('%w', (int) $timestamp)],
            $format
        );
        $date = preg_replace(
            '@%[bB]@',
            $month[(int) strftime('%m', (int) $timestamp) - 1],
            $date
        );

        /* Fill in AM/PM */
        $hours = (int) date('H', (int) $timestamp);
        if ($hours >= 12) {
            $am_pm = _pgettext('AM/PM indication in time', 'PM');
        } else {
            $am_pm = _pgettext('AM/PM indication in time', 'AM');
        }
        $date = preg_replace('@%[pP]@', $am_pm, $date);

        $ret = strftime($date, (int) $timestamp);
        // Some OSes such as Win8.1 Traditional Chinese version did not produce UTF-8
        // output here. See https://github.com/phpmyadmin/phpmyadmin/issues/10598
        if (mb_detect_encoding($ret, 'UTF-8', true) != 'UTF-8') {
            $ret = date('Y-m-d H:i:s', (int) $timestamp);
        }

        return $ret;
    } // end of the 'localisedDate()' function

    /**
     * Splits a URL string by parameter
     *
     * @param string $url the URL
     *
     * @return array  the parameter/value pairs, for example [0] db=sakila
     */
    public static function splitURLQuery($url)
    {
        // decode encoded url separators
        $separator = Url::getArgSeparator();
        // on most places separator is still hard coded ...
        if ($separator !== '&') {
            // ... so always replace & with $separator
            $url = str_replace([htmlentities('&'), '&'], [$separator, $separator], $url);
        }

        $url = str_replace(htmlentities($separator), $separator, $url);
        // end decode

        $url_parts = parse_url($url);

        if (! empty($url_parts['query'])) {
            return explode($separator, $url_parts['query']);
        }

        return [];
    }

    /**
     * Returns a given timespan value in a readable format.
     *
     * @param int $seconds the timespan
     *
     * @return string  the formatted value
     */
    public static function timespanFormat($seconds)
    {
        $days = floor($seconds / 86400);
        if ($days > 0) {
            $seconds -= $days * 86400;
        }

        $hours = floor($seconds / 3600);
        if ($days > 0 || $hours > 0) {
            $seconds -= $hours * 3600;
        }

        $minutes = floor($seconds / 60);
        if ($days > 0 || $hours > 0 || $minutes > 0) {
            $seconds -= $minutes * 60;
        }

        return sprintf(
            __('%s days, %s hours, %s minutes and %s seconds'),
            (string) $days,
            (string) $hours,
            (string) $minutes,
            (string) $seconds
        );
    }

    /**
     * Function added to avoid path disclosures.
     * Called by each script that needs parameters, it displays
     * an error message and, by default, stops the execution.
     *
     * @param string[] $params  The names of the parameters needed by the calling
     *                          script
     * @param boolean  $request Check parameters in request
     *
     * @return void
     *
     * @access public
     */
    public static function checkParameters($params, $request = false)
    {
        $reported_script_name = basename($GLOBALS['PMA_PHP_SELF']);
        $found_error = false;
        $error_message = '';
        if ($request) {
            $array = $_REQUEST;
        } else {
            $array = $GLOBALS;
        }

        foreach ($params as $param) {
            if (! isset($array[$param])) {
                $error_message .= $reported_script_name
                    . ': ' . __('Missing parameter:') . ' '
                    . $param
                    . MySQLDocumentation::showDocumentation('faq', 'faqmissingparameters', true)
                    . '[br]';
                $found_error = true;
            }
        }
        if ($found_error) {
            Core::fatalError($error_message);
        }
    } // end function

    /**
     * Function to generate unique condition for specified row.
     *
     * @param resource       $handle               current query result
     * @param integer        $fields_cnt           number of fields
     * @param stdClass[]     $fields_meta          meta information about fields
     * @param array          $row                  current row
     * @param boolean        $force_unique         generate condition only on pk
     *                                             or unique
     * @param string|boolean $restrict_to_table    restrict the unique condition
     *                                             to this table or false if
     *                                             none
     * @param array|null     $analyzed_sql_results the analyzed query
     *
     * @return array the calculated condition and whether condition is unique
     *
     * @access public
     */
    public static function getUniqueCondition(
        $handle,
        $fields_cnt,
        array $fields_meta,
        array $row,
        $force_unique = false,
        $restrict_to_table = false,
        $analyzed_sql_results = null
    ) {
        $primary_key          = '';
        $unique_key           = '';
        $nonprimary_condition = '';
        $preferred_condition = '';
        $primary_key_array    = [];
        $unique_key_array     = [];
        $nonprimary_condition_array = [];
        $condition_array = [];

        for ($i = 0; $i < $fields_cnt; ++$i) {
            $con_val     = '';
            $field_flags = $GLOBALS['dbi']->fieldFlags($handle, $i);
            $meta        = $fields_meta[$i];

            // do not use a column alias in a condition
            if (! isset($meta->orgname) || strlen($meta->orgname) === 0) {
                $meta->orgname = $meta->name;

                if (! empty($analyzed_sql_results['statement']->expr)) {
                    foreach ($analyzed_sql_results['statement']->expr as $expr) {
                        if (empty($expr->alias) || empty($expr->column)) {
                            continue;
                        }
                        if (strcasecmp($meta->name, $expr->alias) == 0) {
                            $meta->orgname = $expr->column;
                            break;
                        }
                    }
                }
            }

            // Do not use a table alias in a condition.
            // Test case is:
            // select * from galerie x WHERE
            //(select count(*) from galerie y where y.datum=x.datum)>1
            //
            // But orgtable is present only with mysqli extension so the
            // fix is only for mysqli.
            // Also, do not use the original table name if we are dealing with
            // a view because this view might be updatable.
            // (The isView() verification should not be costly in most cases
            // because there is some caching in the function).
            if (isset($meta->orgtable)
                && ($meta->table != $meta->orgtable)
                && ! $GLOBALS['dbi']->getTable($GLOBALS['db'], $meta->table)->isView()
            ) {
                $meta->table = $meta->orgtable;
            }

            // If this field is not from the table which the unique clause needs
            // to be restricted to.
            if ($restrict_to_table && $restrict_to_table != $meta->table) {
                continue;
            }

            // to fix the bug where float fields (primary or not)
            // can't be matched because of the imprecision of
            // floating comparison, use CONCAT
            // (also, the syntax "CONCAT(field) IS NULL"
            // that we need on the next "if" will work)
            if ($meta->type == 'real') {
                $con_key = 'CONCAT(' . self::backquote($meta->table) . '.'
                    . self::backquote($meta->orgname) . ')';
            } else {
                $con_key = self::backquote($meta->table) . '.'
                    . self::backquote($meta->orgname);
            } // end if... else...
            $condition = ' ' . $con_key . ' ';

            if (! isset($row[$i]) || $row[$i] === null) {
                $con_val = 'IS NULL';
            } else {
                // timestamp is numeric on some MySQL 4.1
                // for real we use CONCAT above and it should compare to string
                if ($meta->numeric
                    && ($meta->type != 'timestamp')
                    && ($meta->type != 'real')
                ) {
                    $con_val = '= ' . $row[$i];
                } elseif (($meta->type == 'blob') || ($meta->type == 'string')
                    && false !== stripos($field_flags, 'BINARY')
                    && ! empty($row[$i])
                ) {
                    // hexify only if this is a true not empty BLOB or a BINARY

                    // do not waste memory building a too big condition
                    if (mb_strlen($row[$i]) < 1000) {
                        // use a CAST if possible, to avoid problems
                        // if the field contains wildcard characters % or _
                        $con_val = '= CAST(0x' . bin2hex($row[$i]) . ' AS BINARY)';
                    } elseif ($fields_cnt == 1) {
                        // when this blob is the only field present
                        // try settling with length comparison
                        $condition = ' CHAR_LENGTH(' . $con_key . ') ';
                        $con_val = ' = ' . mb_strlen($row[$i]);
                    } else {
                        // this blob won't be part of the final condition
                        $con_val = null;
                    }
                } elseif (in_array($meta->type, self::getGISDatatypes())
                    && ! empty($row[$i])
                ) {
                    // do not build a too big condition
                    if (mb_strlen($row[$i]) < 5000) {
                        $condition .= '=0x' . bin2hex($row[$i]) . ' AND';
                    } else {
                        $condition = '';
                    }
                } elseif ($meta->type == 'bit') {
                    $con_val = "= b'"
                        . self::printableBitValue((int) $row[$i], (int) $meta->length) . "'";
                } else {
                    $con_val = '= \''
                        . $GLOBALS['dbi']->escapeString($row[$i]) . '\'';
                }
            }

            if ($con_val != null) {
                $condition .= $con_val . ' AND';

                if ($meta->primary_key > 0) {
                    $primary_key .= $condition;
                    $primary_key_array[$con_key] = $con_val;
                } elseif ($meta->unique_key > 0) {
                    $unique_key  .= $condition;
                    $unique_key_array[$con_key] = $con_val;
                }

                $nonprimary_condition .= $condition;
                $nonprimary_condition_array[$con_key] = $con_val;
            }
        } // end for

        // Correction University of Virginia 19991216:
        // prefer primary or unique keys for condition,
        // but use conjunction of all values if no primary key
        $clause_is_unique = true;

        if ($primary_key) {
            $preferred_condition = $primary_key;
            $condition_array = $primary_key_array;
        } elseif ($unique_key) {
            $preferred_condition = $unique_key;
            $condition_array = $unique_key_array;
        } elseif (! $force_unique) {
            $preferred_condition = $nonprimary_condition;
            $condition_array = $nonprimary_condition_array;
            $clause_is_unique = false;
        }

        $where_clause = trim(preg_replace('|\s?AND$|', '', $preferred_condition));
        return [
            $where_clause,
            $clause_is_unique,
            $condition_array,
        ];
    } // end function

    /**
     * Generate the charset query part
     *
     * @param string  $collation Collation
     * @param boolean $override  (optional) force 'CHARACTER SET' keyword
     *
     * @return string
     */
    public static function getCharsetQueryPart($collation, $override = false)
    {
        [$charset] = explode('_', $collation);
        $keyword = ' CHARSET=';

        if ($override) {
            $keyword = ' CHARACTER SET ';
        }
        return $keyword . $charset
            . ($charset == $collation ? '' : ' COLLATE ' . $collation);
    }

    /**
     * Generate a pagination selector for browsing resultsets
     *
     * @param string $name        The name for the request parameter
     * @param int    $rows        Number of rows in the pagination set
     * @param int    $pageNow     current page number
     * @param int    $nbTotalPage number of total pages
     * @param int    $showAll     If the number of pages is lower than this
     *                            variable, no pages will be omitted in pagination
     * @param int    $sliceStart  How many rows at the beginning should always
     *                            be shown?
     * @param int    $sliceEnd    How many rows at the end should always be shown?
     * @param int    $percent     Percentage of calculation page offsets to hop to a
     *                            next page
     * @param int    $range       Near the current page, how many pages should
     *                            be considered "nearby" and displayed as well?
     * @param string $prompt      The prompt to display (sometimes empty)
     *
     * @return string
     *
     * @access  public
     */
    public static function pageselector(
        $name,
        $rows,
        $pageNow = 1,
        $nbTotalPage = 1,
        $showAll = 200,
        $sliceStart = 5,
        $sliceEnd = 5,
        $percent = 20,
        $range = 10,
        $prompt = ''
    ) {
        $increment = floor($nbTotalPage / $percent);
        $pageNowMinusRange = $pageNow - $range;
        $pageNowPlusRange = $pageNow + $range;

        $gotopage = $prompt . ' <select class="pageselector ajax"';

        $gotopage .= ' name="' . $name . '" >';
        if ($nbTotalPage < $showAll) {
            $pages = range(1, $nbTotalPage);
        } else {
            $pages = [];

            // Always show first X pages
            for ($i = 1; $i <= $sliceStart; $i++) {
                $pages[] = $i;
            }

            // Always show last X pages
            for ($i = $nbTotalPage - $sliceEnd; $i <= $nbTotalPage; $i++) {
                $pages[] = $i;
            }

            // Based on the number of results we add the specified
            // $percent percentage to each page number,
            // so that we have a representing page number every now and then to
            // immediately jump to specific pages.
            // As soon as we get near our currently chosen page ($pageNow -
            // $range), every page number will be shown.
            $i = $sliceStart;
            $x = $nbTotalPage - $sliceEnd;
            $met_boundary = false;

            while ($i <= $x) {
                if ($i >= $pageNowMinusRange && $i <= $pageNowPlusRange) {
                    // If our pageselector comes near the current page, we use 1
                    // counter increments
                    $i++;
                    $met_boundary = true;
                } else {
                    // We add the percentage increment to our current page to
                    // hop to the next one in range
                    $i += $increment;

                    // Make sure that we do not cross our boundaries.
                    if ($i > $pageNowMinusRange && ! $met_boundary) {
                        $i = $pageNowMinusRange;
                    }
                }

                if ($i > 0 && $i <= $x) {
                    $pages[] = $i;
                }
            }

            /*
            Add page numbers with "geometrically increasing" distances.

            This helps me a lot when navigating through giant tables.

            Test case: table with 2.28 million sets, 76190 pages. Page of interest
            is between 72376 and 76190.
            Selecting page 72376.
            Now, old version enumerated only +/- 10 pages around 72376 and the
            percentage increment produced steps of about 3000.

            The following code adds page numbers +/- 2,4,8,16,32,64,128,256 etc.
            around the current page.
            */
            $i = $pageNow;
            $dist = 1;
            while ($i < $x) {
                $dist = 2 * $dist;
                $i = $pageNow + $dist;
                if ($i > 0 && $i <= $x) {
                    $pages[] = $i;
                }
            }

            $i = $pageNow;
            $dist = 1;
            while ($i > 0) {
                $dist = 2 * $dist;
                $i = $pageNow - $dist;
                if ($i > 0 && $i <= $x) {
                    $pages[] = $i;
                }
            }

            // Since because of ellipsing of the current page some numbers may be
            // double, we unify our array:
            sort($pages);
            $pages = array_unique($pages);
        }

        foreach ($pages as $i) {
            if ($i == $pageNow) {
                $selected = 'selected="selected" style="font-weight: bold"';
            } else {
                $selected = '';
            }
            $gotopage .= '                <option ' . $selected
                . ' value="' . (($i - 1) * $rows) . '">' . $i . '</option>' . "\n";
        }

        $gotopage .= ' </select>';

        return $gotopage;
    } // end function


    /**
     * Calculate page number through position
     *
     * @param int $pos       position of first item
     * @param int $max_count number of items per page
     *
     * @return int $page_num
     *
     * @access public
     */
    public static function getPageFromPosition($pos, $max_count)
    {
        return (int) floor($pos / $max_count) + 1;
    }

    /**
     * replaces %u in given path with current user name
     *
     * example:
     * <code>
     * $user_dir = userDir('/var/pma_tmp/%u/'); // '/var/pma_tmp/root/'
     *
     * </code>
     *
     * @param string $dir with wildcard for user
     *
     * @return string  per user directory
     */
    public static function userDir($dir)
    {
        // add trailing slash
        if (mb_substr($dir, -1) != '/') {
            $dir .= '/';
        }

        return str_replace('%u', Core::securePath($GLOBALS['cfg']['Server']['user']), $dir);
    }

    /**
     * Clears cache content which needs to be refreshed on user change.
     *
     * @return void
     */
    public static function clearUserCache()
    {
        self::cacheUnset('is_superuser');
        self::cacheUnset('is_createuser');
        self::cacheUnset('is_grantuser');
    }

    /**
     * Calculates session cache key
     *
     * @return string
     */
    public static function cacheKey()
    {
        if (isset($GLOBALS['cfg']['Server']['user'])) {
            return 'server_' . $GLOBALS['server'] . '_' . $GLOBALS['cfg']['Server']['user'];
        }

        return 'server_' . $GLOBALS['server'];
    }

    /**
     * Verifies if something is cached in the session
     *
     * @param string $var variable name
     *
     * @return boolean
     */
    public static function cacheExists($var)
    {
        return isset($_SESSION['cache'][self::cacheKey()][$var]);
    }

    /**
     * Gets cached information from the session
     *
     * @param string  $var      variable name
     * @param Closure $callback callback to fetch the value
     *
     * @return mixed
     */
    public static function cacheGet($var, $callback = null)
    {
        if (self::cacheExists($var)) {
            return $_SESSION['cache'][self::cacheKey()][$var];
        }

        if ($callback) {
            $val = $callback();
            self::cacheSet($var, $val);
            return $val;
        }
        return null;
    }

    /**
     * Caches information in the session
     *
     * @param string $var variable name
     * @param mixed  $val value
     *
     * @return void
     */
    public static function cacheSet($var, $val = null)
    {
        $_SESSION['cache'][self::cacheKey()][$var] = $val;
    }

    /**
     * Removes cached information from the session
     *
     * @param string $var variable name
     *
     * @return void
     */
    public static function cacheUnset($var)
    {
        unset($_SESSION['cache'][self::cacheKey()][$var]);
    }

    /**
     * Converts a bit value to printable format;
     * in MySQL a BIT field can be from 1 to 64 bits so we need this
     * function because in PHP, decbin() supports only 32 bits
     * on 32-bit servers
     *
     * @param int $value  coming from a BIT field
     * @param int $length length
     *
     * @return string the printable value
     */
    public static function printableBitValue(int $value, int $length): string
    {
        // if running on a 64-bit server or the length is safe for decbin()
        if (PHP_INT_SIZE == 8 || $length < 33) {
            $printable = decbin($value);
        } else {
            // FIXME: does not work for the leftmost bit of a 64-bit value
            $i = 0;
            $printable = '';
            while ($value >= pow(2, $i)) {
                ++$i;
            }
            if ($i != 0) {
                --$i;
            }

            while ($i >= 0) {
                if ($value - pow(2, $i) < 0) {
                    $printable = '0' . $printable;
                } else {
                    $printable = '1' . $printable;
                    $value -= pow(2, $i);
                }
                --$i;
            }
            $printable = strrev($printable);
        }
        $printable = str_pad($printable, $length, '0', STR_PAD_LEFT);
        return $printable;
    }

    /**
     * Converts a BIT type default value
     * for example, b'010' becomes 010
     *
     * @param string $bit_default_value value
     *
     * @return string the converted value
     */
    public static function convertBitDefaultValue($bit_default_value)
    {
        return rtrim(ltrim(htmlspecialchars_decode($bit_default_value, ENT_QUOTES), "b'"), "'");
    }

    /**
     * Extracts the various parts from a column spec
     *
     * @param string $columnspec Column specification
     *
     * @return array associative array containing type, spec_in_brackets
     *          and possibly enum_set_values (another array)
     */
    public static function extractColumnSpec($columnspec)
    {
        $first_bracket_pos = mb_strpos($columnspec, '(');
        if ($first_bracket_pos) {
            $spec_in_brackets = rtrim(
                mb_substr(
                    $columnspec,
                    $first_bracket_pos + 1,
                    mb_strrpos($columnspec, ')') - $first_bracket_pos - 1
                )
            );
            // convert to lowercase just to be sure
            $type = mb_strtolower(
                rtrim(mb_substr($columnspec, 0, $first_bracket_pos))
            );
        } else {
            // Split trailing attributes such as unsigned,
            // binary, zerofill and get data type name
            $type_parts = explode(' ', $columnspec);
            $type = mb_strtolower($type_parts[0]);
            $spec_in_brackets = '';
        }

        if ('enum' == $type || 'set' == $type) {
            // Define our working vars
            $enum_set_values = self::parseEnumSetValues($columnspec, false);
            $printtype = $type
                . '(' . str_replace("','", "', '", $spec_in_brackets) . ')';
            $binary = false;
            $unsigned = false;
            $zerofill = false;
        } else {
            $enum_set_values = [];

            /* Create printable type name */
            $printtype = mb_strtolower($columnspec);

            // Strip the "BINARY" attribute, except if we find "BINARY(" because
            // this would be a BINARY or VARBINARY column type;
            // by the way, a BLOB should not show the BINARY attribute
            // because this is not accepted in MySQL syntax.
            if (false !== strpos($printtype, 'binary')
                && ! preg_match('@binary[\(]@', $printtype)
            ) {
                $printtype = str_replace('binary', '', $printtype);
                $binary = true;
            } else {
                $binary = false;
            }

            $printtype = preg_replace(
                '@zerofill@',
                '',
                $printtype,
                -1,
                $zerofill_cnt
            );
            $zerofill = ($zerofill_cnt > 0);
            $printtype = preg_replace(
                '@unsigned@',
                '',
                $printtype,
                -1,
                $unsigned_cnt
            );
            $unsigned = ($unsigned_cnt > 0);
            $printtype = trim($printtype);
        }

        $attribute     = ' ';
        if ($binary) {
            $attribute = 'BINARY';
        }
        if ($unsigned) {
            $attribute = 'UNSIGNED';
        }
        if ($zerofill) {
            $attribute = 'UNSIGNED ZEROFILL';
        }

        $can_contain_collation = false;
        if (! $binary
            && preg_match(
                '@^(char|varchar|text|tinytext|mediumtext|longtext|set|enum)@',
                $type
            )
        ) {
            $can_contain_collation = true;
        }

        // for the case ENUM('&#8211;','&ldquo;')
        $displayed_type = htmlspecialchars($printtype);
        if (mb_strlen($printtype) > $GLOBALS['cfg']['LimitChars']) {
            $displayed_type  = '<abbr title="' . htmlspecialchars($printtype) . '">';
            $displayed_type .= htmlspecialchars(
                mb_substr(
                    $printtype,
                    0,
                    (int) $GLOBALS['cfg']['LimitChars']
                ) . '...'
            );
            $displayed_type .= '</abbr>';
        }

        return [
            'type' => $type,
            'spec_in_brackets' => $spec_in_brackets,
            'enum_set_values'  => $enum_set_values,
            'print_type' => $printtype,
            'binary' => $binary,
            'unsigned' => $unsigned,
            'zerofill' => $zerofill,
            'attribute' => $attribute,
            'can_contain_collation' => $can_contain_collation,
            'displayed_type' => $displayed_type,
        ];
    }

    /**
     * Verifies if this table's engine supports foreign keys
     *
     * @param string $engine engine
     *
     * @return boolean
     */
    public static function isForeignKeySupported($engine)
    {
        $engine = strtoupper((string) $engine);
        if (($engine == 'INNODB') || ($engine == 'PBXT')) {
            return true;
        } elseif ($engine == 'NDBCLUSTER' || $engine == 'NDB') {
            $ndbver = strtolower(
                $GLOBALS['dbi']->fetchValue('SELECT @@ndb_version_string')
            );
            if (substr($ndbver, 0, 4) == 'ndb-') {
                $ndbver = substr($ndbver, 4);
            }
            return version_compare($ndbver, '7.3', '>=');
        }

        return false;
    }

    /**
     * Is Foreign key check enabled?
     *
     * @return bool
     */
    public static function isForeignKeyCheck()
    {
        if ($GLOBALS['cfg']['DefaultForeignKeyChecks'] === 'enable') {
            return true;
        } elseif ($GLOBALS['cfg']['DefaultForeignKeyChecks'] === 'disable') {
            return false;
        }
        return ($GLOBALS['dbi']->getVariable('FOREIGN_KEY_CHECKS') == 'ON');
    }

    /**
     * Handle foreign key check request
     *
     * @return bool Default foreign key checks value
     */
    public static function handleDisableFKCheckInit()
    {
        $default_fk_check_value
            = $GLOBALS['dbi']->getVariable('FOREIGN_KEY_CHECKS') == 'ON';
        if (isset($_REQUEST['fk_checks'])) {
            if (empty($_REQUEST['fk_checks'])) {
                // Disable foreign key checks
                $GLOBALS['dbi']->setVariable('FOREIGN_KEY_CHECKS', 'OFF');
            } else {
                // Enable foreign key checks
                $GLOBALS['dbi']->setVariable('FOREIGN_KEY_CHECKS', 'ON');
            }
        } // else do nothing, go with default
        return $default_fk_check_value;
    }

    /**
     * Cleanup changes done for foreign key check
     *
     * @param bool $default_fk_check_value original value for 'FOREIGN_KEY_CHECKS'
     *
     * @return void
     */
    public static function handleDisableFKCheckCleanup($default_fk_check_value)
    {
        $GLOBALS['dbi']->setVariable(
            'FOREIGN_KEY_CHECKS',
            $default_fk_check_value ? 'ON' : 'OFF'
        );
    }

    /**
     * Converts GIS data to Well Known Text format
     *
     * @param string $data        GIS data
     * @param bool   $includeSRID Add SRID to the WKT
     *
     * @return string GIS data in Well Know Text format
     */
    public static function asWKT($data, $includeSRID = false)
    {
        // Convert to WKT format
        $hex = bin2hex($data);
        $spatialAsText = 'ASTEXT';
        $spatialSrid = 'SRID';
        if ($GLOBALS['dbi']->getVersion() >= 50600) {
            $spatialAsText = 'ST_ASTEXT';
            $spatialSrid = 'ST_SRID';
        }
        $wktsql     = 'SELECT ' . $spatialAsText . "(x'" . $hex . "')";
        if ($includeSRID) {
            $wktsql .= ', ' . $spatialSrid . "(x'" . $hex . "')";
        }

        $wktresult  = $GLOBALS['dbi']->tryQuery(
            $wktsql
        );
        $wktarr     = $GLOBALS['dbi']->fetchRow($wktresult, 0);
        $wktval     = $wktarr[0] ?? null;

        if ($includeSRID) {
            $srid = $wktarr[1] ?? null;
            $wktval = "'" . $wktval . "'," . $srid;
        }
        @$GLOBALS['dbi']->freeResult($wktresult);

        return $wktval;
    }

    /**
     * If the string starts with a \r\n pair (0x0d0a) add an extra \n
     *
     * @param string $string string
     *
     * @return string with the chars replaced
     */
    public static function duplicateFirstNewline($string)
    {
        $first_occurence = mb_strpos($string, "\r\n");
        if ($first_occurence === 0) {
            $string = "\n" . $string;
        }
        return $string;
    }

    /**
     * Get the action word corresponding to a script name
     * in order to display it as a title in navigation panel
     *
     * @param string $target a valid value for $cfg['NavigationTreeDefaultTabTable'],
     *                       $cfg['NavigationTreeDefaultTabTable2'],
     *                       $cfg['DefaultTabTable'] or $cfg['DefaultTabDatabase']
     *
     * @return string|bool Title for the $cfg value
     */
    public static function getTitleForTarget($target)
    {
        $mapping = [
            'structure' =>  __('Structure'),
            'sql' => __('SQL'),
            'search' => __('Search'),
            'insert' => __('Insert'),
            'browse' => __('Browse'),
            'operations' => __('Operations'),
        ];
        return $mapping[$target] ?? false;
    }

    /**
     * Get the script name corresponding to a plain English config word
     * in order to append in links on navigation and main panel
     *
     * @param string $target   a valid value for
     *                         $cfg['NavigationTreeDefaultTabTable'],
     *                         $cfg['NavigationTreeDefaultTabTable2'],
     *                         $cfg['DefaultTabTable'], $cfg['DefaultTabDatabase'] or
     *                         $cfg['DefaultTabServer']
     * @param string $location one out of 'server', 'table', 'database'
     *
     * @return string script name corresponding to the config word
     */
    public static function getScriptNameForOption($target, $location)
    {
        if ($location == 'server') {
            // Values for $cfg['DefaultTabServer']
            switch ($target) {
                case 'welcome':
                    return Url::getFromRoute('/');
                case 'databases':
                    return Url::getFromRoute('/server/databases');
                case 'status':
                    return Url::getFromRoute('/server/status');
                case 'variables':
                    return Url::getFromRoute('/server/variables');
                case 'privileges':
                    return Url::getFromRoute('/server/privileges');
            }
        } elseif ($location == 'database') {
            // Values for $cfg['DefaultTabDatabase']
            switch ($target) {
                case 'structure':
                    return Url::getFromRoute('/database/structure');
                case 'sql':
                    return Url::getFromRoute('/database/sql');
                case 'search':
                    return Url::getFromRoute('/database/search');
                case 'operations':
                    return Url::getFromRoute('/database/operations');
            }
        } elseif ($location == 'table') {
            // Values for $cfg['DefaultTabTable'],
            // $cfg['NavigationTreeDefaultTabTable'] and
            // $cfg['NavigationTreeDefaultTabTable2']
            switch ($target) {
                case 'structure':
                    return Url::getFromRoute('/table/structure');
                case 'sql':
                    return Url::getFromRoute('/table/sql');
                case 'search':
                    return Url::getFromRoute('/table/search');
                case 'insert':
                    return Url::getFromRoute('/table/change');
                case 'browse':
                    return Url::getFromRoute('/sql');
            }
        }

        return $target;
    }

    /**
     * Formats user string, expanding @VARIABLES@, accepting strftime format
     * string.
     *
     * @param string       $string  Text where to do expansion.
     * @param array|string $escape  Function to call for escaping variable values.
     *                              Can also be an array of:
     *                              - the escape method name
     *                              - the class that contains the method
     *                              - location of the class (for inclusion)
     * @param array        $updates Array with overrides for default parameters
     *                              (obtained from GLOBALS).
     *
     * @return string
     */
    public static function expandUserString(
        $string,
        $escape = null,
        array $updates = []
    ) {
        /* Content */
        $vars = [];
        $vars['http_host'] = Core::getenv('HTTP_HOST');
        $vars['server_name'] = $GLOBALS['cfg']['Server']['host'];
        $vars['server_verbose'] = $GLOBALS['cfg']['Server']['verbose'];

        if (empty($GLOBALS['cfg']['Server']['verbose'])) {
            $vars['server_verbose_or_name'] = $GLOBALS['cfg']['Server']['host'];
        } else {
            $vars['server_verbose_or_name'] = $GLOBALS['cfg']['Server']['verbose'];
        }

        $vars['database'] = $GLOBALS['db'];
        $vars['table'] = $GLOBALS['table'];
        $vars['phpmyadmin_version'] = 'phpMyAdmin ' . PMA_VERSION;

        /* Update forced variables */
        foreach ($updates as $key => $val) {
            $vars[$key] = $val;
        }

        /* Replacement mapping */
        /*
         * The __VAR__ ones are for backward compatibility, because user
         * might still have it in cookies.
         */
        $replace = [
            '@HTTP_HOST@' => $vars['http_host'],
            '@SERVER@' => $vars['server_name'],
            '__SERVER__' => $vars['server_name'],
            '@VERBOSE@' => $vars['server_verbose'],
            '@VSERVER@' => $vars['server_verbose_or_name'],
            '@DATABASE@' => $vars['database'],
            '__DB__' => $vars['database'],
            '@TABLE@' => $vars['table'],
            '__TABLE__' => $vars['table'],
            '@PHPMYADMIN@' => $vars['phpmyadmin_version'],
        ];

        /* Optional escaping */
        if ($escape !== null) {
            if (is_array($escape)) {
                $escape_class = new $escape[1];
                $escape_method = $escape[0];
            }
            foreach ($replace as $key => $val) {
                if (isset($escape_class, $escape_method)) {
                    $replace[$key] = $escape_class->$escape_method($val);
                } else {
                    $replace[$key] = $escape == 'backquote'
                        ? self::$escape($val)
                        : $escape($val);
                }
            }
        }

        /* Backward compatibility in 3.5.x */
        if (mb_strpos($string, '@FIELDS@') !== false) {
            $string = strtr($string, ['@FIELDS@' => '@COLUMNS@']);
        }

        /* Fetch columns list if required */
        if (mb_strpos($string, '@COLUMNS@') !== false) {
            $columns_list = $GLOBALS['dbi']->getColumns(
                $GLOBALS['db'],
                $GLOBALS['table']
            );

            // sometimes the table no longer exists at this point
            if ($columns_list !== null) {
                $column_names = [];
                foreach ($columns_list as $column) {
                    if ($escape !== null) {
                        $column_names[] = self::$escape($column['Field']);
                    } else {
                        $column_names[] = $column['Field'];
                    }
                }
                $replace['@COLUMNS@'] = implode(',', $column_names);
            } else {
                $replace['@COLUMNS@'] = '*';
            }
        }

        /* Do the replacement */
        return strtr((string) strftime($string), $replace);
    }

    /**
     * This function processes the datatypes supported by the DB,
     * as specified in Types->getColumns() and either returns an array
     * (useful for quickly checking if a datatype is supported)
     * or an HTML snippet that creates a drop-down list.
     *
     * @param bool   $html     Whether to generate an html snippet or an array
     * @param string $selected The value to mark as selected in HTML mode
     *
     * @return mixed   An HTML snippet or an array of datatypes.
     */
    public static function getSupportedDatatypes($html = false, $selected = '')
    {
        if ($html) {
            $retval = Generator::getSupportedDatatypes($selected);
        } else {
            $retval = [];
            foreach ($GLOBALS['dbi']->types->getColumns() as $value) {
                if (is_array($value)) {
                    foreach ($value as $subvalue) {
                        if ($subvalue !== '-') {
                            $retval[] = $subvalue;
                        }
                    }
                } else {
                    if ($value !== '-') {
                        $retval[] = $value;
                    }
                }
            }
        }

        return $retval;
    } // end getSupportedDatatypes()

    /**
     * Returns a list of datatypes that are not (yet) handled by PMA.
     * Used by: /table/change and libraries/db_routines.inc.php
     *
     * @return array   list of datatypes
     */
    public static function unsupportedDatatypes()
    {
        return [];
    }

    /**
     * Return GIS data types
     *
     * @param bool $upper_case whether to return values in upper case
     *
     * @return string[] GIS data types
     */
    public static function getGISDatatypes($upper_case = false)
    {
        $gis_data_types = [
            'geometry',
            'point',
            'linestring',
            'polygon',
            'multipoint',
            'multilinestring',
            'multipolygon',
            'geometrycollection',
        ];
        if ($upper_case) {
            $gis_data_types = array_map('mb_strtoupper', $gis_data_types);
        }
        return $gis_data_types;
    }

    /**
     * Generates GIS data based on the string passed.
     *
     * @param string $gis_string   GIS string
     * @param int    $mysqlVersion The mysql version as int
     *
     * @return string GIS data enclosed in 'ST_GeomFromText' or 'GeomFromText' function
     */
    public static function createGISData($gis_string, $mysqlVersion)
    {
        $geomFromText = $mysqlVersion >= 50600 ? 'ST_GeomFromText' : 'GeomFromText';
        $gis_string = trim($gis_string);
        $geom_types = '(POINT|MULTIPOINT|LINESTRING|MULTILINESTRING|'
            . 'POLYGON|MULTIPOLYGON|GEOMETRYCOLLECTION)';
        if (preg_match("/^'" . $geom_types . "\(.*\)',[0-9]*$/i", $gis_string)) {
            return $geomFromText . '(' . $gis_string . ')';
        } elseif (preg_match('/^' . $geom_types . '\(.*\)$/i', $gis_string)) {
            return $geomFromText . "('" . $gis_string . "')";
        }

        return $gis_string;
    }

    /**
     * Returns the names and details of the functions
     * that can be applied on geometry data types.
     *
     * @param string $geom_type if provided the output is limited to the functions
     *                          that are applicable to the provided geometry type.
     * @param bool   $binary    if set to false functions that take two geometries
     *                          as arguments will not be included.
     * @param bool   $display   if set to true separators will be added to the
     *                          output array.
     *
     * @return array names and details of the functions that can be applied on
     *               geometry data types.
     */
    public static function getGISFunctions(
        $geom_type = null,
        $binary = true,
        $display = false
    ) {
        $funcs = [];
        if ($display) {
            $funcs[] = ['display' => ' '];
        }

        // Unary functions common to all geometry types
        $funcs['Dimension']    = [
            'params' => 1,
            'type' => 'int',
        ];
        $funcs['Envelope']     = [
            'params' => 1,
            'type' => 'Polygon',
        ];
        $funcs['GeometryType'] = [
            'params' => 1,
            'type' => 'text',
        ];
        $funcs['SRID']         = [
            'params' => 1,
            'type' => 'int',
        ];
        $funcs['IsEmpty']      = [
            'params' => 1,
            'type' => 'int',
        ];
        $funcs['IsSimple']     = [
            'params' => 1,
            'type' => 'int',
        ];

        $geom_type = mb_strtolower(trim((string) $geom_type));
        if ($display && $geom_type != 'geometry' && $geom_type != 'multipoint') {
            $funcs[] = ['display' => '--------'];
        }

        // Unary functions that are specific to each geometry type
        if ($geom_type == 'point') {
            $funcs['X'] = [
                'params' => 1,
                'type' => 'float',
            ];
            $funcs['Y'] = [
                'params' => 1,
                'type' => 'float',
            ];
        } elseif ($geom_type == 'linestring') {
            $funcs['EndPoint']   = [
                'params' => 1,
                'type' => 'point',
            ];
            $funcs['GLength']    = [
                'params' => 1,
                'type' => 'float',
            ];
            $funcs['NumPoints']  = [
                'params' => 1,
                'type' => 'int',
            ];
            $funcs['StartPoint'] = [
                'params' => 1,
                'type' => 'point',
            ];
            $funcs['IsRing']     = [
                'params' => 1,
                'type' => 'int',
            ];
        } elseif ($geom_type == 'multilinestring') {
            $funcs['GLength']  = [
                'params' => 1,
                'type' => 'float',
            ];
            $funcs['IsClosed'] = [
                'params' => 1,
                'type' => 'int',
            ];
        } elseif ($geom_type == 'polygon') {
            $funcs['Area']         = [
                'params' => 1,
                'type' => 'float',
            ];
            $funcs['ExteriorRing'] = [
                'params' => 1,
                'type' => 'linestring',
            ];
            $funcs['NumInteriorRings'] = [
                'params' => 1,
                'type' => 'int',
            ];
        } elseif ($geom_type == 'multipolygon') {
            $funcs['Area']     = [
                'params' => 1,
                'type' => 'float',
            ];
            $funcs['Centroid'] = [
                'params' => 1,
                'type' => 'point',
            ];
            // Not yet implemented in MySQL
            //$funcs['PointOnSurface'] = array('params' => 1, 'type' => 'point');
        } elseif ($geom_type == 'geometrycollection') {
            $funcs['NumGeometries'] = [
                'params' => 1,
                'type' => 'int',
            ];
        }

        // If we are asked for binary functions as well
        if ($binary) {
            // section separator
            if ($display) {
                $funcs[] = ['display' => '--------'];
            }

            if ($GLOBALS['dbi']->getVersion() < 50601) {
                $funcs['Crosses']    = [
                    'params' => 2,
                    'type' => 'int',
                ];
                $funcs['Contains']   = [
                    'params' => 2,
                    'type' => 'int',
                ];
                $funcs['Disjoint']   = [
                    'params' => 2,
                    'type' => 'int',
                ];
                $funcs['Equals']     = [
                    'params' => 2,
                    'type' => 'int',
                ];
                $funcs['Intersects'] = [
                    'params' => 2,
                    'type' => 'int',
                ];
                $funcs['Overlaps']   = [
                    'params' => 2,
                    'type' => 'int',
                ];
                $funcs['Touches']    = [
                    'params' => 2,
                    'type' => 'int',
                ];
                $funcs['Within']     = [
                    'params' => 2,
                    'type' => 'int',
                ];
            } else {
                // If MySQl version is greater than or equal 5.6.1,
                // use the ST_ prefix.
                $funcs['ST_Crosses']    = [
                    'params' => 2,
                    'type' => 'int',
                ];
                $funcs['ST_Contains']   = [
                    'params' => 2,
                    'type' => 'int',
                ];
                $funcs['ST_Disjoint']   = [
                    'params' => 2,
                    'type' => 'int',
                ];
                $funcs['ST_Equals']     = [
                    'params' => 2,
                    'type' => 'int',
                ];
                $funcs['ST_Intersects'] = [
                    'params' => 2,
                    'type' => 'int',
                ];
                $funcs['ST_Overlaps']   = [
                    'params' => 2,
                    'type' => 'int',
                ];
                $funcs['ST_Touches']    = [
                    'params' => 2,
                    'type' => 'int',
                ];
                $funcs['ST_Within']     = [
                    'params' => 2,
                    'type' => 'int',
                ];
            }

            if ($display) {
                $funcs[] = ['display' => '--------'];
            }
            // Minimum bounding rectangle functions
            $funcs['MBRContains']   = [
                'params' => 2,
                'type' => 'int',
            ];
            $funcs['MBRDisjoint']   = [
                'params' => 2,
                'type' => 'int',
            ];
            $funcs['MBREquals']     = [
                'params' => 2,
                'type' => 'int',
            ];
            $funcs['MBRIntersects'] = [
                'params' => 2,
                'type' => 'int',
            ];
            $funcs['MBROverlaps']   = [
                'params' => 2,
                'type' => 'int',
            ];
            $funcs['MBRTouches']    = [
                'params' => 2,
                'type' => 'int',
            ];
            $funcs['MBRWithin']     = [
                'params' => 2,
                'type' => 'int',
            ];
        }
        return $funcs;
    }

    /**
     * Checks if the current user has a specific privilege and returns true if the
     * user indeed has that privilege or false if they don't. This function must
     * only be used for features that are available since MySQL 5, because it
     * relies on the INFORMATION_SCHEMA database to be present.
     *
     * Example:   currentUserHasPrivilege('CREATE ROUTINE', 'mydb');
     *            // Checks if the currently logged in user has the global
     *            // 'CREATE ROUTINE' privilege or, if not, checks if the
     *            // user has this privilege on database 'mydb'.
     *
     * @param string $priv The privilege to check
     * @param mixed  $db   null, to only check global privileges
     *                     string, db name where to also check for privileges
     * @param mixed  $tbl  null, to only check global/db privileges
     *                     string, table name where to also check for privileges
     *
     * @return bool
     */
    public static function currentUserHasPrivilege($priv, $db = null, $tbl = null)
    {
        // Get the username for the current user in the format
        // required to use in the information schema database.
        [$user, $host] = $GLOBALS['dbi']->getCurrentUserAndHost();

        if ($user === '') { // MySQL is started with --skip-grant-tables
            return true;
        }

        $username  = "''";
        $username .= str_replace("'", "''", $user);
        $username .= "''@''";
        $username .= str_replace("'", "''", $host);
        $username .= "''";

        // Prepare the query
        $query = 'SELECT `PRIVILEGE_TYPE` FROM `INFORMATION_SCHEMA`.`%s` '
               . "WHERE GRANTEE='%s' AND PRIVILEGE_TYPE='%s'";

        // Check global privileges first.
        $user_privileges = $GLOBALS['dbi']->fetchValue(
            sprintf(
                $query,
                'USER_PRIVILEGES',
                $username,
                $priv
            )
        );
        if ($user_privileges) {
            return true;
        }
        // If a database name was provided and user does not have the
        // required global privilege, try database-wise permissions.
        if ($db !== null) {
            $query .= " AND '%s' LIKE `TABLE_SCHEMA`";
            $schema_privileges = $GLOBALS['dbi']->fetchValue(
                sprintf(
                    $query,
                    'SCHEMA_PRIVILEGES',
                    $username,
                    $priv,
                    $GLOBALS['dbi']->escapeString($db)
                )
            );
            if ($schema_privileges) {
                return true;
            }
        } else {
            // There was no database name provided and the user
            // does not have the correct global privilege.
            return false;
        }
        // If a table name was also provided and we still didn't
        // find any valid privileges, try table-wise privileges.
        if ($tbl !== null) {
            // need to escape wildcards in db and table names, see bug #3518484
            $tbl = str_replace(['%', '_'], ['\%', '\_'], $tbl);
            $query .= " AND TABLE_NAME='%s'";
            $table_privileges = $GLOBALS['dbi']->fetchValue(
                sprintf(
                    $query,
                    'TABLE_PRIVILEGES',
                    $username,
                    $priv,
                    $GLOBALS['dbi']->escapeString($db),
                    $GLOBALS['dbi']->escapeString($tbl)
                )
            );
            if ($table_privileges) {
                return true;
            }
        }
        // If we reached this point, the user does not
        // have even valid table-wise privileges.
        return false;
    }

    /**
     * Returns server type for current connection
     *
     * Known types are: MariaDB, Percona and MySQL (default)
     *
     * @return string
     */
    public static function getServerType()
    {
        if ($GLOBALS['dbi']->isMariaDB()) {
            return 'MariaDB';
        }

        if ($GLOBALS['dbi']->isPercona()) {
            return 'Percona Server';
        }

        return 'MySQL';
    }

    /**
     * Parses ENUM/SET values
     *
     * @param string $definition The definition of the column
     *                           for which to parse the values
     * @param bool   $escapeHtml Whether to escape html entities
     *
     * @return array
     */
    public static function parseEnumSetValues($definition, $escapeHtml = true)
    {
        $values_string = htmlentities($definition, ENT_COMPAT, 'UTF-8');
        // There is a JS port of the below parser in functions.js
        // If you are fixing something here,
        // you need to also update the JS port.
        $values = [];
        $in_string = false;
        $buffer = '';

        for ($i = 0, $length = mb_strlen($values_string); $i < $length; $i++) {
            $curr = mb_substr($values_string, $i, 1);
            $next = $i == mb_strlen($values_string) - 1
                ? ''
                : mb_substr($values_string, $i + 1, 1);

            if (! $in_string && $curr == "'") {
                $in_string = true;
            } elseif (($in_string && $curr == '\\') && $next == '\\') {
                $buffer .= '&#92;';
                $i++;
            } elseif (($in_string && $next == "'")
                && ($curr == "'" || $curr == '\\')
            ) {
                $buffer .= '&#39;';
                $i++;
            } elseif ($in_string && $curr == "'") {
                $in_string = false;
                $values[] = $buffer;
                $buffer = '';
            } elseif ($in_string) {
                 $buffer .= $curr;
            }
        }

        if (strlen($buffer) > 0) {
            // The leftovers in the buffer are the last value (if any)
            $values[] = $buffer;
        }

        if (! $escapeHtml) {
            foreach ($values as $key => $value) {
                $values[$key] = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
            }
        }

        return $values;
    }

    /**
     * Get regular expression which occur first inside the given sql query.
     *
     * @param array  $regex_array Comparing regular expressions.
     * @param string $query       SQL query to be checked.
     *
     * @return string Matching regular expression.
     */
    public static function getFirstOccurringRegularExpression(array $regex_array, $query): string
    {
        $minimum_first_occurence_index = null;
        $regex = null;

        foreach ($regex_array as $test_regex) {
            if (preg_match($test_regex, $query, $matches, PREG_OFFSET_CAPTURE)) {
                if ($minimum_first_occurence_index === null
                    || ($matches[0][1] < $minimum_first_occurence_index)
                ) {
                    $regex = $test_regex;
                    $minimum_first_occurence_index = $matches[0][1];
                }
            }
        }
        return $regex;
    }

    /**
     * Return the list of tabs for the menu with corresponding names
     *
     * @param string $level 'server', 'db' or 'table' level
     *
     * @return array|null list of tabs for the menu
     */
    public static function getMenuTabList($level = null)
    {
        $tabList = [
            'server' => [
                'databases'   => __('Databases'),
                'sql'         => __('SQL'),
                'status'      => __('Status'),
                'rights'      => __('Users'),
                'export'      => __('Export'),
                'import'      => __('Import'),
                'settings'    => __('Settings'),
                'binlog'      => __('Binary log'),
                'replication' => __('Replication'),
                'vars'        => __('Variables'),
                'charset'     => __('Charsets'),
                'plugins'     => __('Plugins'),
                'engine'      => __('Engines'),
            ],
            'db'     => [
                'structure'   => __('Structure'),
                'sql'         => __('SQL'),
                'search'      => __('Search'),
                'query'       => __('Query'),
                'export'      => __('Export'),
                'import'      => __('Import'),
                'operation'   => __('Operations'),
                'privileges'  => __('Privileges'),
                'routines'    => __('Routines'),
                'events'      => __('Events'),
                'triggers'    => __('Triggers'),
                'tracking'    => __('Tracking'),
                'designer'    => __('Designer'),
                'central_columns' => __('Central columns'),
            ],
            'table'  => [
                'browse'      => __('Browse'),
                'structure'   => __('Structure'),
                'sql'         => __('SQL'),
                'search'      => __('Search'),
                'insert'      => __('Insert'),
                'export'      => __('Export'),
                'import'      => __('Import'),
                'privileges'  => __('Privileges'),
                'operation'   => __('Operations'),
                'tracking'    => __('Tracking'),
                'triggers'    => __('Triggers'),
            ],
        ];

        if ($level == null) {
            return $tabList;
        } elseif (array_key_exists($level, $tabList)) {
            return $tabList[$level];
        }

        return null;
    }

    /**
     * Add fractional seconds to time, datetime and timestamp strings.
     * If the string contains fractional seconds,
     * pads it with 0s up to 6 decimal places.
     *
     * @param string $value time, datetime or timestamp strings
     *
     * @return string time, datetime or timestamp strings with fractional seconds
     */
    public static function addMicroseconds($value)
    {
        if (empty($value) || $value == 'CURRENT_TIMESTAMP'
            || $value == 'current_timestamp()') {
            return $value;
        }

        if (mb_strpos($value, '.') === false) {
            return $value . '.000000';
        }

        $value .= '000000';
        return mb_substr(
            $value,
            0,
            mb_strpos($value, '.') + 7
        );
    }

    /**
     * Reads the file, detects the compression MIME type, closes the file
     * and returns the MIME type
     *
     * @param resource $file the file handle
     *
     * @return string the MIME type for compression, or 'none'
     */
    public static function getCompressionMimeType($file)
    {
        $test = fread($file, 4);
        $len = strlen($test);
        fclose($file);
        if ($len >= 2 && $test[0] == chr(31) && $test[1] == chr(139)) {
            return 'application/gzip';
        }
        if ($len >= 3 && substr($test, 0, 3) == 'BZh') {
            return 'application/bzip2';
        }
        if ($len >= 4 && $test == "PK\003\004") {
            return 'application/zip';
        }
        return 'none';
    }

    /**
     * Provide COLLATE clause, if required, to perform case sensitive comparisons
     * for queries on information_schema.
     *
     * @return string COLLATE clause if needed or empty string.
     */
    public static function getCollateForIS()
    {
        $names = $GLOBALS['dbi']->getLowerCaseNames();
        if ($names === '0') {
            return 'COLLATE utf8_bin';
        } elseif ($names === '2') {
            return 'COLLATE utf8_general_ci';
        }
        return '';
    }

    /**
     * Process the index data.
     *
     * @param array $indexes index data
     *
     * @return array processes index data
     */
    public static function processIndexData(array $indexes)
    {
        $lastIndex    = '';

        $primary      = '';
        $pk_array     = []; // will be use to emphasis prim. keys in the table
        $indexes_info = [];
        $indexes_data = [];

        // view
        foreach ($indexes as $row) {
            // Backups the list of primary keys
            if ($row['Key_name'] == 'PRIMARY') {
                $primary   .= $row['Column_name'] . ', ';
                $pk_array[$row['Column_name']] = 1;
            }
            // Retains keys informations
            if ($row['Key_name'] != $lastIndex) {
                $indexes[] = $row['Key_name'];
                $lastIndex = $row['Key_name'];
            }
            $indexes_info[$row['Key_name']]['Sequences'][] = $row['Seq_in_index'];
            $indexes_info[$row['Key_name']]['Non_unique'] = $row['Non_unique'];
            if (isset($row['Cardinality'])) {
                $indexes_info[$row['Key_name']]['Cardinality'] = $row['Cardinality'];
            }
            // I don't know what does following column mean....
            // $indexes_info[$row['Key_name']]['Packed']          = $row['Packed'];

            $indexes_info[$row['Key_name']]['Comment'] = $row['Comment'];

            $indexes_data[$row['Key_name']][$row['Seq_in_index']]['Column_name']
                = $row['Column_name'];
            if (isset($row['Sub_part'])) {
                $indexes_data[$row['Key_name']][$row['Seq_in_index']]['Sub_part']
                    = $row['Sub_part'];
            }
        } // end while

        return [
            $primary,
            $pk_array,
            $indexes_info,
            $indexes_data,
        ];
    }

    /**
     * Returns whether the database server supports virtual columns
     *
     * @return bool
     */
    public static function isVirtualColumnsSupported()
    {
        $serverType = self::getServerType();
        $serverVersion = $GLOBALS['dbi']->getVersion();
        return in_array($serverType, ['MySQL', 'Percona Server']) && $serverVersion >= 50705
             || ($serverType == 'MariaDB' && $serverVersion >= 50200);
    }

    /**
     * Gets the list of tables in the current db and information about these
     * tables if possible
     *
     * @param string      $db       database name
     * @param string|null $sub_part part of script name
     *
     * @return array
     */
    public static function getDbInfo($db, ?string $sub_part)
    {
        global $cfg;

        /**
         * limits for table list
         */
        if (! isset($_SESSION['tmpval']['table_limit_offset'])
            || $_SESSION['tmpval']['table_limit_offset_db'] != $db
        ) {
            $_SESSION['tmpval']['table_limit_offset'] = 0;
            $_SESSION['tmpval']['table_limit_offset_db'] = $db;
        }
        if (isset($_REQUEST['pos'])) {
            $_SESSION['tmpval']['table_limit_offset'] = (int) $_REQUEST['pos'];
        }
        $pos = $_SESSION['tmpval']['table_limit_offset'];

        /**
         * whether to display extended stats
         */
        $is_show_stats = $cfg['ShowStats'];

        /**
         * whether selected db is information_schema
         */
        $db_is_system_schema = false;

        if ($GLOBALS['dbi']->isSystemSchema($db)) {
            $is_show_stats = false;
            $db_is_system_schema = true;
        }

        /**
         * information about tables in db
         */
        $tables = [];

        $tooltip_truename = [];
        $tooltip_aliasname = [];

        // Special speedup for newer MySQL Versions (in 4.0 format changed)
        if (true === $cfg['SkipLockedTables']) {
            $db_info_result = $GLOBALS['dbi']->query(
                'SHOW OPEN TABLES FROM ' . self::backquote($db) . ' WHERE In_use > 0;'
            );

            // Blending out tables in use
            if ($db_info_result && $GLOBALS['dbi']->numRows($db_info_result) > 0) {
                $tables = self::getTablesWhenOpen($db, $db_info_result);
            } elseif ($db_info_result) {
                $GLOBALS['dbi']->freeResult($db_info_result);
            }
        }

        if (empty($tables)) {
            // Set some sorting defaults
            $sort = 'Name';
            $sort_order = 'ASC';

            if (isset($_REQUEST['sort'])) {
                $sortable_name_mappings = [
                    'table'       => 'Name',
                    'records'     => 'Rows',
                    'type'        => 'Engine',
                    'collation'   => 'Collation',
                    'size'        => 'Data_length',
                    'overhead'    => 'Data_free',
                    'creation'    => 'Create_time',
                    'last_update' => 'Update_time',
                    'last_check'  => 'Check_time',
                    'comment'     => 'Comment',
                ];

                // Make sure the sort type is implemented
                if (isset($sortable_name_mappings[$_REQUEST['sort']])) {
                    $sort = $sortable_name_mappings[$_REQUEST['sort']];
                    if ($_REQUEST['sort_order'] == 'DESC') {
                        $sort_order = 'DESC';
                    }
                }
            }

            $groupWithSeparator = false;
            $tbl_type = null;
            $limit_offset = 0;
            $limit_count = false;
            $groupTable = [];

            if (! empty($_REQUEST['tbl_group']) || ! empty($_REQUEST['tbl_type'])) {
                if (! empty($_REQUEST['tbl_type'])) {
                    // only tables for selected type
                    $tbl_type = $_REQUEST['tbl_type'];
                }
                if (! empty($_REQUEST['tbl_group'])) {
                    // only tables for selected group
                    $tbl_group = $_REQUEST['tbl_group'];
                    // include the table with the exact name of the group if such
                    // exists
                    $groupTable = $GLOBALS['dbi']->getTablesFull(
                        $db,
                        $tbl_group,
                        false,
                        $limit_offset,
                        $limit_count,
                        $sort,
                        $sort_order,
                        $tbl_type
                    );
                    $groupWithSeparator = $tbl_group
                        . $GLOBALS['cfg']['NavigationTreeTableSeparator'];
                }
            } else {
                // all tables in db
                // - get the total number of tables
                //  (needed for proper working of the MaxTableList feature)
                $tables = $GLOBALS['dbi']->getTables($db);
                $total_num_tables = count($tables);
                if (! (isset($sub_part) && $sub_part == '_export')) {
                    // fetch the details for a possible limited subset
                    $limit_offset = $pos;
                    $limit_count = true;
                }
            }
            $tables = array_merge(
                $groupTable,
                $GLOBALS['dbi']->getTablesFull(
                    $db,
                    $groupWithSeparator,
                    $groupWithSeparator !== false,
                    $limit_offset,
                    $limit_count,
                    $sort,
                    $sort_order,
                    $tbl_type
                )
            );
        }

        $num_tables = count($tables);
        //  (needed for proper working of the MaxTableList feature)
        if (! isset($total_num_tables)) {
            $total_num_tables = $num_tables;
        }

        /**
         * If coming from a Show MySQL link on the home page,
         * put something in $sub_part
         */
        if (empty($sub_part)) {
            $sub_part = '_structure';
        }

        return [
            $tables,
            $num_tables,
            $total_num_tables,
            $sub_part,
            $is_show_stats,
            $db_is_system_schema,
            $tooltip_truename,
            $tooltip_aliasname,
            $pos,
        ];
    }

    /**
     * Gets the list of tables in the current db, taking into account
     * that they might be "in use"
     *
     * @param string $db             database name
     * @param object $db_info_result result set
     *
     * @return array list of tables
     */
    public static function getTablesWhenOpen($db, $db_info_result)
    {
        $sot_cache = [];
        $tables = [];

        while ($tmp = $GLOBALS['dbi']->fetchAssoc($db_info_result)) {
            $sot_cache[$tmp['Table']] = true;
        }
        $GLOBALS['dbi']->freeResult($db_info_result);

        // is there at least one "in use" table?
        if (count($sot_cache) > 0) {
            $tblGroupSql = '';
            $whereAdded = false;
            if (Core::isValid($_REQUEST['tbl_group'])) {
                $group = self::escapeMysqlWildcards($_REQUEST['tbl_group']);
                $groupWithSeparator = self::escapeMysqlWildcards(
                    $_REQUEST['tbl_group']
                    . $GLOBALS['cfg']['NavigationTreeTableSeparator']
                );
                $tblGroupSql .= ' WHERE ('
                    . self::backquote('Tables_in_' . $db)
                    . " LIKE '" . $groupWithSeparator . "%'"
                    . ' OR '
                    . self::backquote('Tables_in_' . $db)
                    . " LIKE '" . $group . "')";
                $whereAdded = true;
            }
            if (Core::isValid($_REQUEST['tbl_type'], ['table', 'view'])) {
                $tblGroupSql .= $whereAdded ? ' AND' : ' WHERE';
                if ($_REQUEST['tbl_type'] == 'view') {
                    $tblGroupSql .= " `Table_type` NOT IN ('BASE TABLE', 'SYSTEM VERSIONED')";
                } else {
                    $tblGroupSql .= " `Table_type` IN ('BASE TABLE', 'SYSTEM VERSIONED')";
                }
            }
            $db_info_result = $GLOBALS['dbi']->query(
                'SHOW FULL TABLES FROM ' . self::backquote($db) . $tblGroupSql,
                DatabaseInterface::CONNECT_USER,
                DatabaseInterface::QUERY_STORE
            );
            unset($tblGroupSql, $whereAdded);

            if ($db_info_result && $GLOBALS['dbi']->numRows($db_info_result) > 0) {
                $names = [];
                while ($tmp = $GLOBALS['dbi']->fetchRow($db_info_result)) {
                    if (! isset($sot_cache[$tmp[0]])) {
                        $names[] = $tmp[0];
                    } else { // table in use
                        $tables[$tmp[0]] = [
                            'TABLE_NAME' => $tmp[0],
                            'ENGINE' => '',
                            'TABLE_TYPE' => '',
                            'TABLE_ROWS' => 0,
                            'TABLE_COMMENT' => '',
                        ];
                    }
                } // end while
                if (count($names) > 0) {
                    $tables = array_merge(
                        $tables,
                        $GLOBALS['dbi']->getTablesFull($db, $names)
                    );
                }
                if ($GLOBALS['cfg']['NaturalOrder']) {
                    uksort($tables, 'strnatcasecmp');
                }
            } elseif ($db_info_result) {
                $GLOBALS['dbi']->freeResult($db_info_result);
            }
            unset($sot_cache);
        }
        return $tables;
    }

    /**
     * Returs list of used PHP extensions.
     *
     * @return array of strings
     */
    public static function listPHPExtensions()
    {
        $result = [];
        if (DatabaseInterface::checkDbExtension('mysqli')) {
            $result[] = 'mysqli';
        } else {
            $result[] = 'mysql';
        }

        if (extension_loaded('curl')) {
            $result[] = 'curl';
        }

        if (extension_loaded('mbstring')) {
            $result[] = 'mbstring';
        }

        return $result;
    }

    /**
     * Converts given (request) paramter to string
     *
     * @param mixed $value Value to convert
     *
     * @return string
     */
    public static function requestString($value)
    {
        while (is_array($value) || is_object($value)) {
            $value = reset($value);
        }
        return trim((string) $value);
    }

    /**
     * Generates random string consisting of ASCII chars
     *
     * @param integer $length Length of string
     * @param bool    $asHex  (optional) Send the result as hex
     *
     * @return string
     */
    public static function generateRandom(int $length, bool $asHex = false): string
    {
        $result = '';
        if (class_exists(Random::class)) {
            $random_func = [
                Random::class,
                'string',
            ];
        } else {
            $random_func = 'openssl_random_pseudo_bytes';
        }
        while (strlen($result) < $length) {
            // Get random byte and strip highest bit
            // to get ASCII only range
            $byte = ord($random_func(1)) & 0x7f;
            // We want only ASCII chars
            if ($byte > 32) {
                $result .= chr($byte);
            }
        }
        return $asHex ? bin2hex($result) : $result;
    }

    /**
     * Wraper around PHP date function
     *
     * @param string $format Date format string
     *
     * @return string
     */
    public static function date($format)
    {
        if (defined('TESTSUITE')) {
            return '0000-00-00 00:00:00';
        }
        return date($format);
    }

    /**
     * Wrapper around php's set_time_limit
     *
     * @return void
     */
    public static function setTimeLimit()
    {
        // The function can be disabled in php.ini
        if (function_exists('set_time_limit')) {
            @set_time_limit($GLOBALS['cfg']['ExecTimeLimit']);
        }
    }

    /**
     * Access to a multidimensional array by dot notation
     *
     * @param array        $array   List of values
     * @param string|array $path    Path to searched value
     * @param mixed        $default Default value
     *
     * @return mixed Searched value
     */
    public static function getValueByKey(array $array, $path, $default = null)
    {
        if (is_string($path)) {
            $path = explode('.', $path);
        }
        $p = array_shift($path);
        while (isset($p)) {
            if (! isset($array[$p])) {
                return $default;
            }
            $array = $array[$p];
            $p = array_shift($path);
        }
        return $array;
    }

    /**
     * Creates a clickable column header for table information
     *
     * @param string $title            Title to use for the link
     * @param string $sort             Corresponds to sortable data name mapped
     *                                 in Util::getDbInfo
     * @param string $initialSortOrder Initial sort order
     *
     * @return string Link to be displayed in the table header
     */
    public static function sortableTableHeader($title, $sort, $initialSortOrder = 'ASC')
    {
        $requestedSort = 'table';
        $requestedSortOrder = $futureSortOrder = $initialSortOrder;
        // If the user requested a sort
        if (isset($_REQUEST['sort'])) {
            $requestedSort = $_REQUEST['sort'];
            if (isset($_REQUEST['sort_order'])) {
                $requestedSortOrder = $_REQUEST['sort_order'];
            }
        }
        $orderImg = '';
        $orderLinkParams = [];
        $orderLinkParams['title'] = __('Sort');
        // If this column was requested to be sorted.
        if ($requestedSort == $sort) {
            if ($requestedSortOrder == 'ASC') {
                $futureSortOrder = 'DESC';
                // current sort order is ASC
                $orderImg = ' ' . Generator::getImage(
                        's_asc',
                        __('Ascending'),
                        [
                            'class' => 'sort_arrow',
                            'title' => '',
                        ]
                    );
                $orderImg .= ' ' . Generator::getImage(
                        's_desc',
                        __('Descending'),
                        [
                            'class' => 'sort_arrow hide',
                            'title' => '',
                        ]
                    );
                // but on mouse over, show the reverse order (DESC)
                $orderLinkParams['onmouseover'] = "$('.sort_arrow').toggle();";
                // on mouse out, show current sort order (ASC)
                $orderLinkParams['onmouseout'] = "$('.sort_arrow').toggle();";
            } else {
                $futureSortOrder = 'ASC';
                // current sort order is DESC
                $orderImg = ' ' . Generator::getImage(
                        's_asc',
                        __('Ascending'),
                        [
                            'class' => 'sort_arrow hide',
                            'title' => '',
                        ]
                    );
                $orderImg .= ' ' . Generator::getImage(
                        's_desc',
                        __('Descending'),
                        [
                            'class' => 'sort_arrow',
                            'title' => '',
                        ]
                    );
                // but on mouse over, show the reverse order (ASC)
                $orderLinkParams['onmouseover'] = "$('.sort_arrow').toggle();";
                // on mouse out, show current sort order (DESC)
                $orderLinkParams['onmouseout'] = "$('.sort_arrow').toggle();";
            }
        }
        $urlParams = [
            'db' => $_REQUEST['db'],
            'pos' => 0, // We set the position back to 0 every time they sort.
            'sort' => $sort,
            'sort_order' => $futureSortOrder,
        ];

        if (Core::isValid($_REQUEST['tbl_type'], ['view', 'table'])) {
            $urlParams['tbl_type'] = $_REQUEST['tbl_type'];
        }
        if (! empty($_REQUEST['tbl_group'])) {
            $urlParams['tbl_group'] = $_REQUEST['tbl_group'];
        }

        $url = Url::getFromRoute('/database/structure', $urlParams);

        return Generator::linkOrButton($url, $title . $orderImg, $orderLinkParams);
    }

    /**
     * Check that input is an int or an int in a string
     *
     * @param mixed $input input to check
     *
     * @return bool
     */
    public static function isInteger($input): bool
    {
        return ctype_digit((string) $input);
    }

    /**
     * Build titles and icons for action links
     *
     * @return array   the action titles
     */
    public static function buildActionTitles(): array
    {
        $titles = [];

        $titles['Browse'] = Generator::getIcon('b_browse', __('Browse'));
        $titles['NoBrowse'] = Generator::getIcon('bd_browse', __('Browse'));
        $titles['Search'] = Generator::getIcon('b_select', __('Search'));
        $titles['NoSearch'] = Generator::getIcon('bd_select', __('Search'));
        $titles['Insert'] = Generator::getIcon('b_insrow', __('Insert'));
        $titles['NoInsert'] = Generator::getIcon('bd_insrow', __('Insert'));
        $titles['Structure'] = Generator::getIcon('b_props', __('Structure'));
        $titles['Drop'] = Generator::getIcon('b_drop', __('Drop'));
        $titles['NoDrop'] = Generator::getIcon('bd_drop', __('Drop'));
        $titles['Empty'] = Generator::getIcon('b_empty', __('Empty'));
        $titles['NoEmpty'] = Generator::getIcon('bd_empty', __('Empty'));
        $titles['Edit'] = Generator::getIcon('b_edit', __('Edit'));
        $titles['NoEdit'] = Generator::getIcon('bd_edit', __('Edit'));
        $titles['Export'] = Generator::getIcon('b_export', __('Export'));
        $titles['NoExport'] = Generator::getIcon('bd_export', __('Export'));
        $titles['Execute'] = Generator::getIcon('b_nextpage', __('Execute'));
        $titles['NoExecute'] = Generator::getIcon('bd_nextpage', __('Execute'));
        // For Favorite/NoFavorite, we need icon only.
        $titles['Favorite'] = Generator::getIcon('b_favorite', '');
        $titles['NoFavorite'] = Generator::getIcon('b_no_favorite', '');

        return $titles;
    }
}
