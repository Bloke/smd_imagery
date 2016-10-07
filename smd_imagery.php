<?php

// This is a PLUGIN TEMPLATE for Textpattern CMS.

// Copy this file to a new name like abc_myplugin.php.  Edit the code, then
// run this file at the command line to produce a plugin for distribution:
// $ php abc_myplugin.php > abc_myplugin-0.1.txt

// Plugin name is optional.  If unset, it will be extracted from the current
// file name. Plugin names should start with a three letter prefix which is
// unique and reserved for each plugin author ("abc" is just an example).
// Uncomment and edit this line to override:
$plugin['name'] = 'smd_imagery';

// Allow raw HTML help, as opposed to Textile.
// 0 = Plugin help is in Textile format, no raw HTML allowed (default).
// 1 = Plugin help is in raw HTML.  Not recommended.
# $plugin['allow_html_help'] = 1;

$plugin['version'] = '0.10';
$plugin['author'] = 'Stef Dawson';
$plugin['author_uri'] = 'http://stefdawson.com/';
$plugin['description'] = 'A Textpattern CMS plugin for managing images in the Write panel.';

// Plugin load order:
// The default value of 5 would fit most plugins, while for instance comment
// spam evaluators or URL redirectors would probably want to run earlier
// (1...4) to prepare the environment for everything else that follows.
// Values 6...9 should be considered for plugins which would work late.
// This order is user-overrideable.
$plugin['order'] = '5';

// Plugin 'type' defines where the plugin is loaded
// 0 = public              : only on the public side of the website (default)
// 1 = public+admin        : on both the public and admin side
// 2 = library             : only when include_plugin() or require_plugin() is called
// 3 = admin               : only on the admin side (no AJAX)
// 4 = admin+ajax          : only on the admin side (AJAX supported)
// 5 = public+admin+ajax   : on both the public and admin side (AJAX supported)
$plugin['type'] = '4';

// Plugin "flags" signal the presence of optional capabilities to the core plugin loader.
// Use an appropriately OR-ed combination of these flags.
// The four high-order bits 0xf000 are available for this plugin's private use
if (!defined('PLUGIN_HAS_PREFS')) define('PLUGIN_HAS_PREFS', 0x0001); // This plugin wants to receive "plugin_prefs.{$plugin['name']}" events
if (!defined('PLUGIN_LIFECYCLE_NOTIFY')) define('PLUGIN_LIFECYCLE_NOTIFY', 0x0002); // This plugin wants to receive "plugin_lifecycle.{$plugin['name']}" events

$plugin['flags'] = '2';

// Plugin 'textpack' is optional. It provides i18n strings to be used in conjunction with gTxt().
// Syntax:
// ## arbitrary comment
// #@event
// #@language ISO-LANGUAGE-CODE
// abc_string_name => Localized String

$plugin['textpack'] = <<<EOT
#@smd_imagery
smd_imagery_dialog_title => Insert images
smd_imagery_fetch_btn => Fetch
smd_imagery_id_btn => Get IDs
smd_imagery_image => Image
smd_imagery_opt_cat => By category
smd_imagery_opt_fld => From field
smd_imagery_organise_btn => Manage
smd_imagery_result => Result
smd_imagery_template => Template
EOT;

if (!defined('txpinterface'))
        @include_once('zem_tpl.php');

# --- BEGIN PLUGIN CODE ---
/**
 * smd_imagery
 *
 * A Textpattern CMS plugin for managing images in the Write panel.
 *
 * @author Stef Dawson
 * @link   http://stefdawson.com/
 */
if (txpinterface === 'admin') {
    new smd_imagery();
}

// 4.5.x polyfill.
if (!function_exists('send_json_response')) {
    function send_json_response($out = '')
    {
        static $headers_sent = false;

        if (!$headers_sent) {
            ob_clean();
            header('Content-Type: application/json; charset=utf-8');
            txp_status_header('200 OK');
            $headers_sent = true;
        }

        if (!is_string($out)) {
            $out = json_encode($out);
        }

        echo $out;
    }
}

/**
 * Admin interface.
 */
class smd_imagery
{
    /**
     * The plugin's event as registered in Txp.
     *
     * @var string
     */
    protected $plugin_event = 'smd_imagery';

    /**
     * Constructor to set up callbacks and environment.
     */
    public function __construct()
    {
        add_privs($this->plugin_event, '1,2,3,4,5,6');
        register_callback(array($this, 'welcome'), 'plugin_lifecycle.' . $this->plugin_event);
        register_callback(array($this, 'render_ui'), 'article_ui', 'article_image');
        register_callback(array($this, 'inject_head'), 'admin_side', 'head_end');

        // Handler for calls from the plugin's UI buttons.
        register_callback(array($this, 'dispatch'), $this->plugin_event);

        // Handlers for 4.6+ core Txp-based events.
        // These run 'pre' so they can inject stuff into the POSTed array
        // prior to Txp's involvement.
        // The reason it's not for earlier versions is that the Article Image field
        // wasn't volatile, so the new id values aren't injected.
        if (version_compare(txp_version, '4.6.0', '>=')) {
            register_callback(array($this, 'replacePOST'), 'article', 'edit', 1);
            register_callback(array($this, 'replacePOST'), 'article', 'create', 1);
        }
    }

    /**
     * Install/uninstall jumpoff point.
     *
     * @param  string $evt  Textpattern event (lifecycle)
     * @param  string $stp  Textpattern step (action)
     */
    public function welcome($evt, $stp)
    {
        switch ($stp) {
            case 'deleted':
                safe_delete('txp_lang', "event like '" . $this->plugin_event . "%'");
                break;
        }

        return;
    }

    /**
     * Divert plugin callbacks to the correct function.
     *
     * @param  string $evt Textpattern event (panel)
     * @param  string $stp Textpattern step (action)
     */
    public function dispatch($evt, $stp)
    {
        $available_steps = array(
            'fetchImages'  => true,
            'renderDialog' => true,
            'replaceJSON'  => true,
            'saveState'    => true,
        );

        if (!bouncer($stp, $available_steps)) {
            return;
        }

        $this->$stp($evt, $stp);
    }

    /**
     * Inject style rules / header material into the &lt;head&gt; of the page.
     *
     * @param  string $evt Textpattern event (panel)
     * @param  string $stp Textpattern step (action)
     * @return string      Content to inject, or nothing if not the plugin's $event
     */
    public function inject_head($evt, $stp)
    {
        global $event;

        // Also add fallback to load jQuery UI in case we're on < Txp 4.6.
        if ($event === 'article') {
            echo '<style>
.ui-dialog { min-width: 50vw; }
.content-image { padding: 0; border-radius: none; }
img { max-width: 100%; }
.smd_imagery_images { display: flex; flex-wrap: wrap; }
.smd_imagery_image { max-width: 33%; position: relative; }
.smd_imagery_image .destroy { position: absolute; right: 0; z-index: 15; }
.smd_imagery_btn { margin-bottom: 1rem; }
</style>'
                .n.script_js(<<<EOJS
window.jQuery.ui || document.write('<link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/smoothness/jquery-ui.css" media="screen" /><script src="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js" integrity="sha256-VazP97ZCwtekAsvgPBSUwPFKdrwD3unUfSGVYrahUqU=" crossorigin="anonymous"><\/script>');
EOJS
            );
        }

        return;
    }

    /**
     * Add buttons to the article image field.
     *
     * @param  string $evt  Textpattern event (panel)
     * @param  string $stp  Textpattern step (action)
     * @param  string $data Original markup
     * @param  array  $rs   Accompanyng record set
     * @return string       HTML
     */
    public function render_ui($evt, $stp, $data, $rs)
    {
        $btn = '<button class="' . $this->plugin_event . '_fetch '.$this->plugin_event.'_btn">' . gTxt('smd_imagery_id_btn') . '</button>'
            .n. '<button class="' . $this->plugin_event . '_organise '.$this->plugin_event.'_btn">' . gTxt('smd_imagery_organise_btn') . '</button>'
            .n. '<div class="' . $this->plugin_event . '_dialog" title="' . gTxt('smd_imagery_dialog_title') . '">'
            .n. '</div>';

        $js = script_js(<<< EOJS
jQuery(function() {
    /**
     * Fetch button handler.
     *
     * Grabs images by category name and stuffs them directly in the article image field.
     */
    jQuery('.{$this->plugin_event}_fetch').on('click', function(ev) {
        ev.preventDefault();
        var me = jQuery(this);
        var body = jQuery('body');
        var spinner = jQuery('<span />').addClass('spinner');

        // Show feedback while processing.
        me.addClass('busy').attr('disabled', true).after(spinner);
        body.addClass('busy');

        var dest = jQuery('.article-image input');

        sendAsyncEvent({
                event        : '{$this->plugin_event}',
                step         : 'replaceJSON',
                articleImage : dest.val(),
            }, function() {}, 'json')
            .done(function (data, textStatus, jqXHR) {
                dest.val(data);
            })
            .always(function () {
                me.removeClass('busy').removeAttr('disabled');
                spinner.remove();
                body.removeClass('busy');
            });
    });

    /**
     * Throw up a dialog to allow image order to be manipulated by hand.
     */
    jQuery('.{$this->plugin_event}_organise').on('click', function(ev) {
        ev.preventDefault();
        var me = jQuery(this);
        var body = jQuery('body');
        var spinner = jQuery('<span />').addClass('spinner');

        // Show feedback while processing.
        me.addClass('busy').attr('disabled', true).after(spinner);
        body.addClass('busy');

        var dest = jQuery('.{$this->plugin_event}_dialog');

        sendAsyncEvent({
                event : '{$this->plugin_event}',
                step  : 'renderDialog',
            }, function() {}, 'json')
            .done(function (data, textStatus, jqXHR) {
                dest.empty().html(data.content);
                dest.dialog({
                    // Expensive, but prevents multiple dialogs appearing
                    // after article Save.
                    close: function(event, ui) {
                        dest.empty().dialog('destroy');
                    }
                });
            })
            .always(function () {
                me.removeClass('busy').removeAttr('disabled');
                spinner.remove();
                body.removeClass('busy');
            });

    });
});
EOJS
    );

        return $data.$btn.$js;
    }

    /**
     * Draw the bare, starting UI for the dialog.
     *
     * @param  string $evt Textpattern event (panel)
     * @param  string $stp Textpattern step (action)
     * @return HTML
     */
    public function renderDialog($evt, $stp)
    {
        global $plugins;

        $out = '';
        $smdthumb = (is_array($plugins) and in_array('smd_thumbnail', $plugins)) ? '1' : '0';
        $thumb_sizes = array();
        $thumb_sizes['txp_thumb'] = gTxt('thumbnail');
        $thumb_sizes['txp_image'] = gTxt('smd_imagery_image');

        if ($smdthumb == '1') {
            $rs = smd_thumb_get_profiles();

            if ($rs) {
                foreach ($rs as $row) {
                    $thumb_sizes[$row['name']] = $row['name'];
                }
            }
        }

        $thumbSelector = selectInput(
            'smd_imagery_size',
            $thumb_sizes,
            get_pref('smd_imagery_size'),
            false,
            '',
            'smd_imagery_size'
        );

        $catList = event_category_popup('image', '', $this->plugin_event . '_list');

        $fldChoices = array(
            'article-image' => gTxt('article_image'),
            'body'          => gTxt('body'),
            'excerpt'       => gTxt('excerpt'),
            );
        $cfs = getCustomFields();

        foreach ($cfs as $i => $cf_name) {
            $fldChoices['custom-'.$i] = get_pref("custom_{$i}_set");
        }

        $chosenMethod = get_pref('smd_imagery_load_method', 'bycat');

        $fldList = selectInput($this->plugin_event . '_field', $fldChoices, get_pref('smd_imagery_field'), false, '', $this->plugin_event . '_field');

        $loadChoices = array(
            'bycat' => gTxt('smd_imagery_opt_cat'),
            'byfld' => gTxt('smd_imagery_opt_fld'),
            );

        $loadMethod = radioSet($loadChoices, $this->plugin_event . '_load_method', $chosenMethod);

        $actions = '<button class="' . $this->plugin_event . '_fetch_img">' . gTxt('smd_imagery_fetch_btn') . '</button>';

        $sortable = $this->sortOpts();
        $sort = get_pref('smd_imagery_sort_order', key($sortable));
        $dir = get_pref('smd_imagery_sort_dir', 'asc');

        $listActions = '<span class="smd_imagery_sortopts">'
            . selectInput('smd_imagery_sort_order', $sortable, $sort, false, '', 'smd_imagery_sort_order')
            . (($dir === 'asc')
                ? href('<span class="ui-icon ui-icon-arrowthick-1-n smd_imagery_sort_dir"></span> ', '#')
                : href('<span class="ui-icon ui-icon-arrowthick-1-s smd_imagery_sort_dir"></span> ', '#')
            )
            . '</span>';

        $panel = '<div class="' . $this->plugin_event . '_images">'
            .n. '</div>';
        $plate = '<label for="' . $this->plugin_event . '_template">' . gTxt('smd_imagery_template') . '</label>'
            .n. '<textarea name="' . $this->plugin_event . '_template" id="' . $this->plugin_event . '_template" class="' . $this->plugin_event . '_template">'.txpspecialchars(get_pref('smd_imagery_template')).'</textarea>';
        $result = '<label for="' . $this->plugin_event . '_result">' . gTxt('smd_imagery_result') . '</label>'
            .n. '<textarea name="' . $this->plugin_event . '_result" id="' . $this->plugin_event . '_result" class="' . $this->plugin_event . '_result" rows=4"></textarea>';
        $js = script_js(<<< EOJS
jQuery(function() {

    /**
     * Fetch button handler.
     *
     * Grabs images by category name and shows them in the dialog.
     */
    jQuery('.{$this->plugin_event}_fetch_img').on('click', function(ev) {
        ev.preventDefault();
        var me = jQuery(this);
        var body = jQuery('body');
        var spinner = jQuery('<span />').addClass('spinner');

        // Show feedback while processing.
        me.addClass('busy').attr('disabled', true).after(spinner);
        body.addClass('busy');

        var type = jQuery('[name={$this->plugin_event}_load_method]:checked').val()
        var catName = null;
        var idList = [];
        var nameList = [];

        if (type === 'bycat') {
            catName = jQuery('#{$this->plugin_event}_list').val();
        } else if (type === 'byfld') {
            var theField = jQuery('#{$this->plugin_event}_field').val();
            var content = jQuery('#' + theField).val();

            // Check for an entire list of ids.
            var rex = /^([0-9, ]+)$/g;
            result = rex.exec(content);

            if (result !== null) {
                if (typeof(result[1]) != 'undefined') {
                    idList.push(result[1]);
               }
            }

            // Check for txp:image/txp:images tags and pull out ids/names.
            // Very simplistic. The downside to separating passes will only
            // become apparent if people mix HTML and Txp tags, as the
            // images will not be in source order.
            // In reality this probably won't be an issue.
            var rex = /<txp:image[s]?.*((id)\s*=\s*"([0-9, ]+)"|(name)\s*=\s*"(.+?)").*?\/?>/g;

            while ((result = rex.exec(content)) !== null) {
                if (typeof(result) != 'undefined') {
                    if (typeof(result[3]) != 'undefined') {
                        // id matches
                        idList.push(result[3].trim());
                    } else if (typeof(result[5]) != 'undefined') {
                        // name matches
                        nameList.push(result[5].trim());
                    }
                }
            }

            // Next, check for HTML img tags and extract file ids.
            var rex = /<img.*(src)\s*=\s*".*?\/([0-9]+)\..{3,4}".*?\/?>/g;

            while ((result = rex.exec(content)) !== null) {
                if (typeof(result) != 'undefined') {
                    if (typeof(result[2]) != 'undefined') {
                        // id found
                        idList.push(result[2]);
                    }
                }
            }
        }

        var size = jQuery('#{$this->plugin_event}_size').val();
        var dest = jQuery('.{$this->plugin_event}_images');

        sendAsyncEvent({
                event    : '{$this->plugin_event}',
                step     : 'fetchImages',
                type     : type,
                category : catName,
                idList   : idList.join(','),
                nameList : nameList.join(','),
                size     : size,
            }, function() {}, 'json')
            .done(function (data, textStatus, jqXHR) {
                // Drag & drop, yeah!
                dest.empty().html(data.content).sortable({
                    tolerance: "pointer",
                    opacity: 0.9,
                    create: smd_imagery_result,
                    change: smd_imagery_result,
                    update: smd_imagery_result
                });

                jQuery('.{$this->plugin_event}_image .destroy').click(function(ev) {
                    ev.preventDefault();
                    var target = jQuery(this).closest('.{$this->plugin_event}_image');
                    target.hide('fast', function() { target.remove(); smd_imagery_result(); });
                });

                smd_imagery_result();
            })
            .always(function () {
                me.removeClass('busy').removeAttr('disabled');
                spinner.remove();
                body.removeClass('busy');
            });
    });

    /**
     * Save pane state: loadMethod.
     */
    jQuery('[name={$this->plugin_event}_load_method]').on('change', function(ev) {
        var meVal = jQuery(this).filter(':checked').val();
        var catObj = jQuery('#{$this->plugin_event}_list');
        var fldObj = jQuery('#{$this->plugin_event}_field');
        var ordObj = jQuery('.{$this->plugin_event}_sortopts');

        if (meVal === 'bycat') {
            fldObj.hide();
            catObj.show();
            ordObj.show();
        } else if (meVal === 'byfld') {
            fldObj.show();
            catObj.hide();
            ordObj.hide();
        }

        smd_imagery_stash(['load_method']);
    }).change();

    /**
     * Save pane state: size, field and sort order.
     */
    jQuery('#{$this->plugin_event}_size, #{$this->plugin_event}_field, #{$this->plugin_event}_sort_order').on('change', function() {
        var toStash = [
            'size',
            'field',
            'sort_order'
        ];

        smd_imagery_stash(toStash);
    });

    /**
     * Save pane state: sort dir.
     */
    jQuery('.{$this->plugin_event}_sort_dir').on('click', function(ev) {
        ev.preventDefault();
        jQuery(this).toggleClass('ui-icon-arrowthick-1-s ui-icon-arrowthick-1-n');
        smd_imagery_stash(['sort_dir']);
    });

    /**
     * Save pane state: template.
     */
    jQuery('.{$this->plugin_event}_template').on('blur', function() {
        smd_imagery_stash(['plate']);
        smd_imagery_result();
    });
});

/**
 * Store pane state.
 */
function smd_imagery_stash(things) {
    sort_dir = jQuery('.{$this->plugin_event}_sort_dir').hasClass('ui-icon-arrowthick-1-n') ? 'asc' : 'desc'

    var opts = {
        event      : '{$this->plugin_event}',
        step       : 'saveState',
        loadMethod : 'null',
        size       : 'null',
        field      : 'null',
        sort_order : 'null',
        sort_dir   : 'null',
        plate      : 'null',
    };

    jQuery(things).each(function(idx, thing) {
        switch (thing) {
            case 'load_method':
                opts.loadMethod = jQuery('[name={$this->plugin_event}_load_method]:checked').val();
                break;
            case 'size':
                opts.size = jQuery('#{$this->plugin_event}_size').val();
                break;
            case 'field':
                opts.field = jQuery('#{$this->plugin_event}_field').val();
                break;
            case 'sort_order':
                opts.sort_order = jQuery('#{$this->plugin_event}_sort_order').val();
                break;
            case 'sort_dir':
                opts.sort_dir = sort_dir;
                break;
            case 'plate':
                opts.plate = jQuery('.{$this->plugin_event}_template').val();
                break;
        }
    });

    sendAsyncEvent(opts);
}

/**
 * jQuery UI callback to update the results textarea.
 */
function smd_imagery_result(event, ui) {
    var meta = {
        "id"   : [],
        "name" : [],
    };

    var out = '';
    var reps = {};

    jQuery('.{$this->plugin_event}_images img').each(function(idx) {
        meta.id.push(jQuery(this).data('ref'));
        meta.name.push(jQuery(this).data('name'));
    });

    var imgIdList = meta.id.join(',');
    var imgNameList = meta.name.join(',');

    var plate = jQuery('.{$this->plugin_event}_template').val();
    var re1 = /\{smd_imagery_list_(.+)\}/i;
    var re2 = /\{smd_imagery_(id|name)\}/i;
    var foundList = plate.match(re1);
    var foundSingle = plate.match(re2);

    // Default output is an id list, if no matches found.
    out = imgIdList;

    if (foundList) {
        reps["{{$this->plugin_event}_list_id}"] = imgIdList;
        reps["{{$this->plugin_event}_list_name}"] = imgNameList;
        reps["{{$this->plugin_event}_list_id_quoted}"] = "'" + meta.id.join("','") + "'";
        reps["{{$this->plugin_event}_list_name_quoted}"] = "'" + meta.name.join("','") + "'";
        out = plate.strtr(reps);
    } else if (foundSingle) {
        out = '';

        jQuery(meta.id).each(function(idx, val) {
            reps["{{$this->plugin_event}_id}"] = val;
            reps["{{$this->plugin_event}_name}"] = meta.name[idx];
            out += plate.strtr(reps) + '\\n';
        });
    }

    jQuery('.{$this->plugin_event}_result').val(out);
}

/**
 * Equivalent to PHP's strtr().
 *
 * From https://gist.github.com/dsheiko/2774533.
 */
String.prototype.strtr = function (replacePairs) {
    "use strict";
    var str = this.toString(), key, re;

    for (key in replacePairs) {
        if (replacePairs.hasOwnProperty(key)) {
            re = new RegExp(key, "g");
            str = str.replace(re, replacePairs[key]);
        }
    }

    return str;
}
EOJS
        );

        $out = $loadMethod
            .br. $catList
            .n. $fldList
            .n. $thumbSelector
            .n. $listActions.$actions
            .n. $panel
            .n. $plate
            .n. $result
            .n. $js;

        // Have to send the template content separately so it can be rendered
        // after the content. Otherwise, jQuery converts self-closed tags to
        // fully closed when rendering via the html() method.
        send_json_response(array('content' => $out));
    }

    /**
     * Grab the images from the POSTed category.
     *
     * Permits selection of uncategorised images by deliberately not
     * checking if the passed category is empty.
     *
     * @param  string $evt Textpattern event (panel)
     * @param  string $stp Textpattern step (action)
     * @return HTML
     */
    public function fetchImages($evt, $stp)
    {
        global $img_dir;

        $type = ps('type');
        $where = '';

        switch ($type) {
            case 'bycat':
                $sortable = $this->sortOpts();
                $dirable = array('asc', 'desc');
                $catName = ps('category');
                $sort = get_pref('smd_imagery_sort_order');
                $dir = get_pref('smd_imagery_sort_dir');

                if (!array_key_exists($sort, $sortable)) {
                    $sort = key($sortable);
                }

                if (!in_array($dir, $dirable)) {
                    $dir = $dirable[0];
                }

                $orderBy = ' ORDER BY ' . doSlash($sort) . ' ' . doSlash($dir);
                $where = "category = '" . doSlash($catName) . "'" . $orderBy;
                break;
            case 'byfld':
                $idList = ps('idList');
                $nameList = ps('nameList');

                if ($idList) {
                    $ids = doSlash($idList);
                    $where = "id IN(" . $ids . ") ORDER BY field(id, " . $ids . ")";
                }

                if ($nameList) {
                    $names = implode(', ', quote_list(do_list($nameList)));
                    $where = "name IN(" . $names . ") ORDER BY field(name, " . $names . ")";
                }

                break;
        }


        $size = ps('size');
        $img = array();

        $rs = safe_rows('*', 'txp_image', $where);

        foreach ($rs as $row) {
            switch ($size) {
                case 'txp_thumb':
                    // Thumbnail.
                    $url = imagesrcurl($row['id'], $row['ext'], true);
                    break;
                case 'txp_image':
                    // Full-size image.
                    $url = imagesrcurl($row['id'], $row['ext']);
                    break;
                default:
                    // smd_thumbnail profile.
                    $url = ihu . $img_dir . '/' . $size . '/' . $row['id'] . $row['ext'];
                    break;
            }

            $aspect = ($row['h'] == $row['w']) ? ' square' : (($row['h'] > $row['w']) ? ' portrait' : ' landscape');
            $img_info = $row['id'].$row['ext'].' ('.$row['w'].' &#215; '.$row['h'].')';
            $img[] = '<div class="'.$this->plugin_event.'_image' . $aspect . '">'
                .n. '<button
                    class="destroy"
                    title="'.gTxt('delete').'"
                    aria-label="' . gTxt('delete') . '"><span class="ui-icon ui-icon-close">' . gTxt('delete') . '</button>'
                .n. '<img class="content-image"
                    src="' . $url . '"
                    alt="' . $img_info . '"
                    title="' . $img_info . '"
                    data-ref="' . $row['id'] . '"
                    data-name="' . $row['name'] . '"
                    />'
                .n. '</div>';
        }

        $out = implode(n, $img);

        send_json_response(array('content' => $out));
    }

    /**
     * Store the state of the UI for layter recall.
     *
     * @param  string $evt Textpattern event (panel)
     * @param  string $stp Textpattern step (action)
     */
    public function saveState($evt, $stp)
    {
        $loadMethod = ps('loadMethod');
        $size = ps('size');
        $field = ps('field');
        $sort = ps('sort_order');
        $dir = ps('sort_dir');
        $plate = ps('plate');

        if ($loadMethod !== "null") {
            set_pref('smd_imagery_load_method', $loadMethod, $this->plugin_event, 2, null, 0, PREF_PRIVATE);
        }

        if ($size !== "null") {
            set_pref('smd_imagery_size', $size, $this->plugin_event, 2, null, 0, PREF_PRIVATE);
        }

        if ($field !== "null") {
            set_pref('smd_imagery_field', $field, $this->plugin_event, 2, null, 0, PREF_PRIVATE);
        }

        if ($sort !== "null") {
            set_pref('smd_imagery_sort_order', $sort, $this->plugin_event, 2, null, 0, PREF_PRIVATE);
        }

        if ($dir !== "null") {
            set_pref('smd_imagery_sort_dir', $dir, $this->plugin_event, 2, null, 0, PREF_PRIVATE);
        }
 
        if ($plate !== "null") {
            set_pref('smd_imagery_template', $plate, $this->plugin_event, 2, null, 0, PREF_PRIVATE);
        }

        send_json_response(array('lm' => $loadMethod, 'sz' => $size, 'fl' => $field, 'so' => $sort, 'dr' => $dir, 'pl' => $plate));
    }

    /**
     * Look up any categories and return all id values via JSON.
     *
     * @param  string $evt Textpattern event (panel)
     * @param  string $stp Textpattern step (action)
     * @return JSON        List of IDs
     */
    public function replaceJSON($evt, $stp)
    {
        $data = ps('articleImage');
        $idList = $this->replace($data);
        send_json_response(json_encode($idList));
    }

    /**
     * Look up any categories and return all id values during save.
     *
     * @param  string $evt Textpattern event (panel)
     * @param  string $stp Textpattern step (action)
     * @return string      List of IDs
     */
    public function replacePOST($evt, $stp)
    {
        $data = ps('Image');
        $idList = $this->replace($data);
        $_POST['Image'] = $idList;
    }

    /**
     * Replace any categories in the passed string with id values.
     *
     * Any duplicate values are only included once.
     *
     * @param  string $data The list of IDs / cat names
     * @return string       List of IDs
     */
    protected function replace($data)
    {
        if ($data) {
            $reps = array();

            // Create an array of IDs/cat names.
            $items = do_list($data);

            // Get just the category names (if any).
            $cats = array_filter($items, array($this, 'filterCats'));

            if ($cats) {
                // Could use group_concat, but it's MySQL specific. Think of the future.
                $rs = safe_rows('id, category', 'txp_image', 'category IN(' . implode(',', quote_list($cats)) . ')');

                // Extract IDs into a category-indexed array, as long as they
                // haven't been seen before.
                foreach ($rs as $row) {
                    if (!in_array($row['id'], $items)) {
                        $reps[$row['category']][] = $row['id'];
                    }
                }

                // Replace any element in the original $items array that
                // matches a category name pulled from the DB, with the
                // concatenated list of id values it represents.
                if ($reps) {
                    foreach ($reps as $cat => $ids) {
                        $pos = array_search($cat, $items);

                        if ($pos !== false) {
                            $items[$pos] = implode(',', $ids);
                        }
                    }
                }

                // Squish al items into a single comma-separated list.
                $data = implode(',', $items);
            }
        }

        return $data;
    }

    /**
     * array_filter callback to remove purely numeric id values.
     *
     * @param  string $v Value to compare, from array_filter() function
     * @return bool
     */
    protected function filterCats($v)
    {
        return !is_numeric($v);
    }

    /**
     * Get a list of valid image fields to sort by.
     *
     * @return array
     */
    protected function sortOpts()
    {
        return array(
            'name'   => gTxt('name'),
            'id'     => gTxt('ID'),
            'date'   => gTxt('date'),
            'author' => gTxt('author'),
            'ext'    => gTxt('extension'),
            'w'      => gTxt('width'),
            'h'      => gTxt('height'),
        );
    }
}
# --- END PLUGIN CODE ---
if (0) {
?>
<!--
# --- BEGIN PLUGIN HELP ---
h1. smd_imagery

Insert images into your Write panel. Very handy for people who run photoblog or image-heavy sites, or those who categorise images for inclusion in articles.

h2. Features

* Replace category names with image id values in the Article Image field.
* Comma-separate category names to grab id values from them all at once.
* Order of image id values is preserved.
* Craft custom image sets based on an image category, or load images from an article field.
* Build a list of id values or a complete tag to paste into your article via a template.
* Fast and efficient: only one query.

h2. Installation / Uninstallation

p(information). Requires Textpattern 4.5+

p(information). Recommended: Textpattern 4.6+

"Download the plugin":https://github.com/Bloke/smd_imagery/releases, paste the code into the Textpattern _Admin->Plugins_ panel, install and enable the plugin. For bug reports, please "raise an issue":https://github.com/Bloke/smd_imagery/issues.

To uninstall, delete the plugin from the _Admin->Plugins_ panel.

h2. Usage

Once installed, two new buttons labelled _Fetch_ and _Manage_ appear below the Article Image field. Both of these allow you to insert images into your article, but they do it in different ways, as detailed below.

h3. Populating Article Image by category

Use the _Fetch_ button to immediately ask the database to grab the image id values from any category names listed in the _Article Image_ field. If you are using Textpattern v4.6.0 or later, just save or publish the article: the image id values will be fetched for you automatically and populated in the _Article Image_ box.

Notes:

* The order of id values already present in the Article Image field is perserved after replacement.
* Any categories that do not exist (or typos) will remain in the list.
* Any category names that are wholly numeric will _not_ be fetched, as the plugin cannot distinguish between them and id values.
* The maximum number of characters -- incuding commas and spaces -- that can be stored in the Article Image field is 255 by default. This is why the plugin doesn't put spaces between its id values. If you insert a category name that results in the image id list exceeding this number of characters, the article will throw an error when saving.

h3. Examples

h4. Example 1

* Create an image category called @holiday_snaps@.
* Upload some images and assign them to that category.
* On the Write panel, type @holiday_snaps@ into the Article Image field.
* Hit _Fetch_.
* Note that @holiday_snaps@ has been replaced with a list of image id values that were assoaciated with that category.

h4. Example 2

* Create a few image categories.
* Upload some images to each.
* On the Write panel, list the image category names in the Article Image field.
* Hit _Fetch_ and note the content of the Article Image field has been populated with the id values from each of the categories.

h4. Example 3

Do something similar to Example 2, but before httng the _Fetch_ button, sprinkle some other image id values in the Article Image field. For example:

bc. 15, 42, holiday_snaps, 6, night-out, 129

After hitting _Fetch_, note that the id values pulled from the database are replaced in-situ. Also note that if one or more of the id values you've typed are the same as any in the categories you're fetching, the duplicates will not be fetched. Further, if all of the id values that comprise a category are already in the Article Image field, that category name will remain in the list.

h3. Crafting image lists by hand

For more control over your image lists, and where you can insert the resulting values, use the _Manage_ button. This will pop up a dialog box that contains a radio button to allow you to choose between two methods of fetching images:

* By category: to load images by the chosen category, at the chosen size, ordered by the chosen property in the desired order depicted by the arrow.
* From field: to load images from the selected article field, in the order defined in that field.

In the latter case, the plugin searches for images in the following order:

# A straight list of id values.
# @<txp:images id="x, y, z, ...">@.
# @<txp:images name="file1.jpg, file2.png, file3.jpg, ...">@.
# @<txp:image id="x" />@ @<txp:image id="y" />@ @<txp:image id="z" />@ ...
# @<img src="http://example.org/images/x.jpg" />@ @<img src="http://example.org/images/y.png" />@ @<img src="http://example.org/images/z.jpg" />@ ...

Notes:

* Selecting the empty entry at the top of the category list will fetch all uncategorized images.
* If you have the smd_thumbnail plugin installed, any active profiles you have defined are also available in the 'size' dropdown.
* If you choose 'Image' as the size, it will fetch every full-size image in the chosen category. This may take some time if the number of images is large!

Once you have selected your image category/field and properties, hit the nearby _Fetch_ button. All images that match the criteria of that size will be loaded in the dialog window for your consideration. You may drag and drop the images to reorder them to taste, or hit the 'x' button to remove an image from the list. It *will not* delete the real image on disk, just remove it from the list in the dialog box.

As you alter the images in the dialog box, the _Result_ textarea box at the bottom of the dialog is updated in real-time to reflect the list of id values that represent your chosen image set. At any time you can copy that list and paste it into the Article Image field, a custom field, the body, etc in order to build your gallery.

You may also use the _Template_ box to specify a template into which the list will be inserted. The entire set will then be available in the _Result_ box. This is primarily designed for creating tags such as @<txp:images>@ from your lists. For example, you could define your tag template as:

bc. <txp:images id="{smd_imagery_list_id}" form="gallery" />

Whichever images you choose in the list, their id values will be inserted in place of the @{smd_imagery_list_id}@, in real-time. If you prefer image names, you may elect to define your tag something like this:

bc. <txp:images name="{smd_imagery_list_name}" form="gallery"
   wraptag="div" class="photos" />

There are also a pair of specialised replacement tags to return quoted lists of ids or names: @{smd_imagery_list_id_quoted}@ or @{smd_imagery_list_name_quoted}@.

If you wish to insert each image individually, you might like one of these templates:

bc. <txp:image id="{smd_imagery_id}" />

or

bc. <txp:image name="{smd_imagery_name}" />

You can copy and paste the complete tag(s) from the _Result_ box and paste it into your article somewhere, or use it as a sneaky tag builder for galleries. Your template could even include an smd_macro! If using the core tags, your @gallery@ Form can, of course, be used to render anything you like using the @<txp:image>@, @<txp:image_info>@ and @<txp:image_url>@ et al tags.

Notes:

* Your chosen image size, field, template tag and sort options are automatically remembered and recalled each time you open the dialog box so you can rapidly build galleries on photo-heavy sites.
* When using the @<txp:images>@ tag to construct galleries using its @name@ attribute, the resulting gallery _will not contain images in the order you specify_. Only its @id@ attrbute will return them in the defined order.
* The dialog box is dog ugly under Txp 4.5.x because jQuery UI is not included, nor styled to match the admin theme. Using the plugin under Txp 4.6+ offers a much cleaner experience.
# --- END PLUGIN HELP ---
-->
<?php
}
?>