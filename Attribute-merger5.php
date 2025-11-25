<?php
/**
 * Plugin Name: WooCommerce Attribute Merger
 * Description: Complete Suite: Batch Merge + Rescue + Repair + SQL Data Inspector (v4.6).
 * Version: 4.6
 * Author: MatejDEVs
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class WC_Attribute_Merger {

    private $log_dir;
    private $log_file;

    public function __construct() {
        $upload_dir = wp_upload_dir();
        $this->log_dir = $upload_dir['basedir'] . '/wcam-logs/';
        $this->log_file = $this->log_dir . 'wcam-log-' . date('Y-m-d') . '.txt';

        if (!file_exists($this->log_dir)) {
            wp_mkdir_p($this->log_dir);
            file_put_contents($this->log_dir . 'index.php', '<?php // Silence is golden');
            file_put_contents($this->log_dir . '.htaccess', 'deny from all');
        }

        if (!file_exists($this->log_file)) {
            file_put_contents($this->log_file, "TIME | STEP | PID | ACTION | VARS_BEFORE | VARS_AFTER | HAS_ANY | DETAILS" . PHP_EOL);
        }

        add_action('admin_menu', [$this, 'register_menu_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_ajax_wcam_run_batch', [$this, 'ajax_run_batch']);
        add_action('wp_ajax_wcam_run_repair_batch', [$this, 'ajax_run_repair_batch']);
        add_action('wp_ajax_wcam_run_rescue', [$this, 'ajax_run_rescue']);
        add_action('wp_ajax_wcam_get_terms', [$this, 'ajax_get_terms']);
        add_action('wp_ajax_wcam_manual_merge', [$this, 'ajax_manual_merge']);
        add_action('wp_ajax_wcam_debug_product', [$this, 'ajax_debug_product']); // Updated for Inspector
        add_action('wp_ajax_wcam_repair_product', [$this, 'ajax_repair_product']);
    }

    private function write_log($step, $pid, $action, $details, $before = 0, $after = 0, $has_any = 'NO') {
        $time = date('H:i:s');
        $screen_msg = "{$step} | {$pid} | {$action} | Before:{$before} -> After:{$after} | Any:{$has_any} | {$details}\n";
        $file_msg = "{$time}|{$step}|{$pid}|{$action}|{$before}|{$after}|{$has_any}|{$details}";
        file_put_contents($this->log_file, $file_msg . PHP_EOL, FILE_APPEND);
        return $screen_msg;
    }

    public function enqueue_scripts($hook) {
        if (strpos($hook, 'wc-attr-merger') === false) return;

        wp_enqueue_script('jquery');
        wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', ['jquery'], '4.1.0', true);
        wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', [], '4.1.0');

        wp_add_inline_style('common', '
            .wcam-card { background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); padding: 20px; margin-top: 20px; max-width: 1000px; }
            .wcam-header { border-bottom: 1px solid #eee; padding-bottom: 15px; margin-bottom: 15px; }
            .wcam-row { display: flex; gap: 20px; }
            .wcam-col { flex: 1; }
            .wcam-scroll { max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #f9f9f9; }
            .wcam-log-container { background: #2c3338; color: #f0f0f1; padding: 15px; font-family: monospace; height: 400px; overflow-y: scroll; white-space: pre; border-radius: 4px; margin-top: 15px; font-size: 11px; }
            .wcam-progress-wrap { background: #f0f0f1; height: 25px; border-radius: 15px; overflow: hidden; margin: 20px 0; border: 1px solid #ccc; display: none; }
            .wcam-progress-bar { background: #2271b1; height: 100%; width: 0%; transition: width 0.2s; text-align: center; color: #fff; line-height: 24px; font-size: 12px; font-weight: bold; }
            .wcam-notice { background: #fff8e5; border-left: 4px solid #ffb900; padding: 10px; margin: 10px 0; }
            .wcam-tool-section { border-top: 1px solid #ddd; background: #fcfcfc; padding: 20px; margin-top: 30px; }
            
            /* Inspector Tables */
            .wcam-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; background: #fff; border: 1px solid #e5e5e5; }
            .wcam-table th, .wcam-table td { text-align: left; padding: 8px; border-bottom: 1px solid #eee; font-size: 12px; }
            .wcam-table th { background: #f9f9f9; font-weight: 600; }
            .wcam-table tr:hover { background: #f0f0f1; }
            .wcam-badge { display: inline-block; padding: 2px 6px; border-radius: 3px; font-size: 10px; font-weight: bold; background: #eee; color: #666; margin-right: 5px; }
            .wcam-badge.good { background: #d4edda; color: #155724; }
            .wcam-badge.bad { background: #f8d7da; color: #721c24; }
        ');

        wp_add_inline_script('common', '
            jQuery(document).ready(function($) {
                var isRunning = false;
                var totalItems = 0;
                var processed = 0;
                var page = 1;

                $(".wcam-select2").select2();

                // --- PROCESS STARTERS ---
                $("#wcam-form").on("submit", function(e) {
                    e.preventDefault();
                    if (isRunning) return;
                    startProcess("wcam_run_batch", $("select[name=\'target_attr\']").val());
                });

                $("#wcam-rescue-form").on("submit", function(e) {
                    e.preventDefault();
                    if (isRunning) return;
                    var target = $("select[name=\'rescue_target_attr\']").val();
                    if(!target) { alert("Select attribute."); return; }
                    if(!confirm("STRICT MODE: Fix numeric terms?")) return;
                    startProcess("wcam_run_rescue", target);
                });
                
                $("#wcam-global-repair-btn").on("click", function(e) {
                    e.preventDefault();
                    if (isRunning) return;
                    if(!confirm("Start Global Auto-Repair?")) return;
                    startProcess("wcam_run_repair_batch", "repair");
                });

                // --- DEBUG & INSPECT ---
                $("#wcam-debug-btn").on("click", function(e) {
                    e.preventDefault();
                    var pid = $("#wcam-debug-id").val();
                    if(!pid) { alert("Enter Product ID"); return; }
                    
                    $("#wcam-inspector-output").html("<p>Loading data for Product #" + pid + "...</p>");
                    
                    $.post(ajaxurl, { action: "wcam_debug_product", product_id: pid, nonce: $("#wcam_nonce").val() }, function(res) { 
                        $("#wcam-inspector-output").html(res.data); 
                    });
                });

                $("#wcam-repair-btn").on("click", function(e) {
                    e.preventDefault();
                    var pid = $("#wcam-debug-id").val();
                    if(!pid) { alert("Enter Product ID"); return; }
                    if(!confirm("Attempt repair on this single product?")) return;
                    
                    $.post(ajaxurl, { action: "wcam_repair_product", product_id: pid, nonce: $("#wcam_nonce").val() }, function(res) { 
                        alert(res.data);
                        // Auto refresh inspector
                        $("#wcam-debug-btn").click();
                    });
                });

                // --- MANUAL MERGE ---
                $("#wcam-manual-tool-select").on("change", function() {
                    var tax = $(this).val(); if(!tax) return;
                    $.post(ajaxurl, { action: "wcam_get_terms", taxonomy: "pa_" + tax }, function(res) {
                        if(res.success) {
                            var opts = "<option value=\'\'>-- Select Term --</option>";
                            $.each(res.data, function(k, v) { opts += "<option value=\'" + v.term_id + "\'>" + v.name + " (" + v.count + ")</option>"; });
                            $("#wcam-term-from, #wcam-term-to").html(opts).trigger("change");
                        }
                    });
                });

                $("#wcam-manual-merge-btn").on("click", function(e) {
                    e.preventDefault();
                    var tax = $("#wcam-manual-tool-select").val();
                    var fromId = $("#wcam-term-from").val();
                    var toId = $("#wcam-term-to").val();
                    if(!tax || !fromId || !toId) { alert("Missing fields"); return; }
                    if(fromId == toId) { alert("Same term selected"); return; }
                    if(!confirm("Merge terms?")) return;
                    $(this).prop("disabled", true).text("Merging...");
                    $.post(ajaxurl, { action: "wcam_manual_merge", taxonomy: "pa_" + tax, from_id: fromId, to_id: toId, nonce: $("#wcam_nonce").val() }, function(res) {
                        if(res.success) { alert(res.data); location.reload(); } 
                        else { alert("Error: " + res.data); $("#wcam-manual-merge-btn").prop("disabled", false).text("Merge Terms"); }
                    });
                });

                // --- PROCESS RUNNER ---
                function startProcess(action, target) {
                    var sources = [];
                    if (action === "wcam_run_batch") {
                        $("input[name=\'source_attrs[]\']:checked").each(function() { sources.push($(this).val()); });
                        if (!target || sources.length === 0) { alert("Configuration missing."); return; }
                        if (sources.includes(target)) { alert("Target cannot be in sources."); return; }
                    }
                    
                    var label = "PROCESS";
                    if(action === "wcam_run_rescue") label = "RESCUE";
                    if(action === "wcam_run_batch") label = "MERGE";
                    if(action === "wcam_run_repair_batch") label = "AUTO-REPAIR";

                    $(".wcam-log-container").html("STEP | PID | ACTION | BEFORE -> AFTER | HAS ANY? | DETAILS\n--------------------------------------------------------------------------\n");
                    $(".wcam-progress-wrap").show();
                    $(".wcam-progress-bar").css("width", "0%").text("0%");
                    $("input[type=submit], button").prop("disabled", true);
                    
                    isRunning = true;
                    processed = 0;
                    page = 1;
                    runStep(action, target, sources);
                }

                function runStep(action, target, sources) {
                    var data = {
                        action: action,
                        target_attr: target,
                        source_attrs: sources,
                        dry_run: $("input[name=\'dry_run\']").is(":checked") ? 1 : 0,
                        page: page,
                        nonce: $("#wcam_nonce").val()
                    };

                    $.post(ajaxurl, data, function(response) {
                        if (!response.success) {
                            log("ERROR | N/A | FATAL | " + (response.data || "Unknown error"));
                            isRunning = false;
                            $("input[type=submit], button").prop("disabled", false);
                            return;
                        }
                        var r = response.data;
                        if (r.log) log(r.log);
                        if (page === 1 && r.total) {
                            totalItems = r.total;
                        }
                        processed += r.count;
                        var pct = 0;
                        if(totalItems > 0) pct = Math.round((processed / totalItems) * 100);
                        if (pct > 100) pct = 100;
                        $(".wcam-progress-bar").css("width", pct + "%").text(pct + "% (" + processed + "/" + totalItems + ")");
                        if (!r.done && isRunning) {
                            page++;
                            runStep(action, target, sources);
                        } else {
                            isRunning = false;
                            log("DONE | N/A | COMPLETE | Process finished successfully.");
                            $("input[type=submit], button").prop("disabled", false);
                            $(".wcam-progress-bar").css("background", "#46b450");
                        }
                    }).fail(function() {
                        log("ERROR | N/A | TIMEOUT | Retrying in 3 seconds...");
                        setTimeout(function(){ runStep(action, target, sources); }, 3000);
                    });
                }
                function log(msg) {
                    var $c = $(".wcam-log-container");
                    $c.append(msg);
                    $c.scrollTop($c[0].scrollHeight);
                }
            });
        ');
    }

    public function register_menu_page() {
        add_submenu_page('tools.php', 'Attribute Merger', 'Attribute Merger', 'manage_options', 'wc-attr-merger', [$this, 'render_admin_page']);
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) return;
        $attribute_taxonomies = wc_get_attribute_taxonomies();
        ?>
        <div class="wrap">
            <h1>WooCommerce Attribute Merger v4.6 (Inspector)</h1>
            <input type="hidden" id="wcam_nonce" value="<?php echo wp_create_nonce('wcam_ajax_nonce'); ?>">
            
            <div class="wcam-card">
                <div class="wcam-notice"><p><strong>Log File:</strong> Logs are saved to <code>/wp-content/uploads/wcam-logs/</code>.</p></div>
                <form id="wcam-form">
                    <h2>1. Merge Attributes</h2>
                    <div class="wcam-row">
                        <div class="wcam-col">
                            <h3>Target</h3>
                            <select name="target_attr" style="width: 100%;">
                                <option value="">-- Select Target --</option>
                                <?php foreach ($attribute_taxonomies as $tax): ?>
                                    <option value="<?php echo esc_attr($tax->attribute_name); ?>">
                                        <?php echo esc_html($tax->attribute_label); ?> (<?php echo esc_html($tax->attribute_name); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="wcam-col">
                            <h3>Sources</h3>
                            <div class="wcam-scroll">
                                <?php foreach ($attribute_taxonomies as $tax): ?>
                                    <label style="display:block;margin-bottom:5px;">
                                        <input type="checkbox" name="source_attrs[]" value="<?php echo esc_attr($tax->attribute_name); ?>">
                                        <?php echo esc_html($tax->attribute_label); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div style="margin-top:15px;">
                        <label><input type="checkbox" name="dry_run" value="1" checked> <strong>Dry Run</strong></label>
                        <input type="submit" class="button button-primary" value="Start Merge" style="margin-left:10px;">
                    </div>
                </form>
                <div class="wcam-progress-wrap"><div class="wcam-progress-bar">0%</div></div>
                <div class="wcam-log-container">Ready...</div>

                <div class="wcam-tool-section">
                    <h2>2. Cleanup Tools</h2>
                    <div style="margin-bottom: 30px; border-bottom: 1px solid #eee; padding-bottom: 20px;">
                        <h3 style="color:#d63638;">A. Fix Numeric Terms (Rescue)</h3>
                        <form id="wcam-rescue-form">
                            <select name="rescue_target_attr" style="width: 300px;">
                                <option value="">-- Select Broken Attribute --</option>
                                <?php foreach ($attribute_taxonomies as $tax): ?>
                                    <option value="<?php echo esc_attr($tax->attribute_name); ?>"><?php echo esc_html($tax->attribute_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="submit" class="button" value="Run Auto-Fix">
                        </form>
                    </div>
                    <div style="margin-bottom: 30px; border-bottom: 1px solid #eee; padding-bottom: 20px;">
                        <h3 style="color:#2271b1;">B. Manual Term Merger</h3>
                        <div style="display:flex; gap:10px; align-items:flex-end;">
                            <div><label><strong>1. Attribute</strong></label><br>
                                <select id="wcam-manual-tool-select" style="width: 200px;">
                                    <option value="">-- Select --</option>
                                    <?php foreach ($attribute_taxonomies as $tax): ?>
                                        <option value="<?php echo esc_attr($tax->attribute_name); ?>"><?php echo esc_html($tax->attribute_label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div><label><strong>2. Bad Term</strong></label><br><select id="wcam-term-from" class="wcam-select2" style="width: 250px;"><option value="">Select Attribute First</option></select></div>
                            <div style="padding-bottom:5px; font-weight:bold;">&rarr;</div>
                            <div><label><strong>3. Good Term</strong></label><br><select id="wcam-term-to" class="wcam-select2" style="width: 250px;"><option value="">Select Attribute First</option></select></div>
                            <div><button id="wcam-manual-merge-btn" class="button button-primary">Merge Terms</button></div>
                        </div>
                    </div>
                    <div>
                        <h3 style="color:#826eb4;">3. Global Auto-Repair</h3>
                        <div style="background:#f0f0f5; padding:15px; border-radius:5px; margin-bottom:15px;">
                             <p>Scans variable products (1 by 1) for "Any..." variations and auto-links them.</p>
                             <button id="wcam-global-repair-btn" class="button button-primary button-large">Run Auto-Repair on ALL Products</button>
                        </div>
                    </div>
                </div>

                <div class="wcam-tool-section">
                    <h2>4. SQL Data Inspector</h2>
                    <p>View raw database values to debug specific products.</p>
                    <div style="display:flex; gap:10px; align-items:center; margin-bottom: 15px;">
                        <input type="number" id="wcam-debug-id" placeholder="Product ID (e.g. 96919)" style="width: 200px; padding:8px;">
                        <button id="wcam-debug-btn" class="button button-primary">Inspect Database</button>
                        <button id="wcam-repair-btn" class="button button-secondary">Repair This Product</button>
                    </div>
                    <div id="wcam-inspector-output" style="border-top:1px solid #ccc; padding-top:15px;"></div>
                </div>
            </div>
        </div>
        <?php
    }

    // --- INSPECTOR HANDLER ---
    public function ajax_debug_product() {
        check_ajax_referer('wcam_ajax_nonce', 'nonce');
        $pid = intval($_POST['product_id']);
        $product = wc_get_product($pid);
        
        if(!$product) { wp_send_json_success("<div class='wcam-notice'>Product #{$pid} not found.</div>"); return; }
        
        ob_start();
        echo "<h3 style='margin-top:0;'>Product: " . esc_html($product->get_name()) . " (<a href='" . get_edit_post_link($pid) . "' target='_blank'>Edit</a>)</h3>";
        echo "<p><strong>Type:</strong> " . $product->get_type() . "</p>";

        // PARENT ATTRIBUTES
        echo "<h4>Parent Attributes (_product_attributes)</h4>";
        $attributes = $product->get_attributes();
        if(empty($attributes)) {
            echo "<p>No attributes found on parent.</p>";
        } else {
            echo "<table class='wcam-table'><thead><tr><th>Taxonomy</th><th>Is Variation?</th><th>Term IDs</th><th>Decoded Terms</th></tr></thead><tbody>";
            foreach($attributes as $tax => $attr) {
                $is_var = $attr->get_variation() ? "<span class='wcam-badge good'>YES</span>" : "<span class='wcam-badge'>NO</span>";
                $term_ids = implode(', ', $attr->get_options());
                
                $term_names = [];
                foreach($attr->get_options() as $tid) {
                    $t = get_term($tid);
                    if($t && !is_wp_error($t)) $term_names[] = $t->name . " (Slug: " . $t->slug . ")";
                    else $term_names[] = "Unknown ID " . $tid;
                }
                echo "<tr><td><strong>{$tax}</strong></td><td>{$is_var}</td><td>{$term_ids}</td><td>" . implode('<br>', $term_names) . "</td></tr>";
            }
            echo "</tbody></table>";
        }

        // VARIATIONS
        if($product->is_type('variable')) {
            echo "<h4>Variations (Children)</h4>";
            $children = $product->get_children();
            if(empty($children)) {
                echo "<p>No variations found.</p>";
            } else {
                echo "<table class='wcam-table'><thead><tr><th>Var ID</th><th>Status</th><th>Attribute Keys (Meta)</th><th>Values (Slug)</th><th>Action</th></tr></thead><tbody>";
                foreach($children as $cid) {
                    $child = wc_get_product($cid);
                    $meta = get_post_meta($cid);
                    $keys = [];
                    $values = [];
                    $is_any = true;

                    foreach($meta as $key => $val) {
                        if(strpos($key, 'attribute_') === 0) {
                            $keys[] = $key;
                            $values[] = $val[0];
                            if(!empty($val[0])) $is_any = false;
                        }
                    }
                    
                    $status_html = $is_any ? "<span class='wcam-badge bad'>ANY...</span>" : "<span class='wcam-badge good'>DEFINED</span>";
                    if(empty($keys)) $status_html = "<span class='wcam-badge bad'>NO ATTRIBUTES</span>";

                    echo "<tr>";
                    echo "<td>#{$cid}</td>";
                    echo "<td>{$status_html}</td>";
                    echo "<td>" . implode('<br>', $keys) . "</td>";
                    echo "<td>" . implode('<br>', $values) . "</td>";
                    echo "<td><a href='" . get_edit_post_link($cid) . "' target='_blank'>Edit</a></td>";
                    echo "</tr>";
                }
                echo "</tbody></table>";
            }
        }
        
        $output = ob_get_clean();
        wp_send_json_success($output);
    }

    // --- REPAIR LOGIC ---
    public function ajax_repair_product() {
        check_ajax_referer('wcam_ajax_nonce', 'nonce');
        $pid = intval($_POST['product_id']);
        $product = wc_get_product($pid);
        if(!$product || !$product->is_type('variable')) { wp_send_json_success("Invalid product."); return; }
        wp_send_json_success($this->repair_variable_product($product));
    }

    private function repair_variable_product($product) {
        $pid = $product->get_id();
        $parent_attributes = $product->get_attributes();
        $valid_taxonomies = [];
        $parent_terms_map = []; 
        
        foreach($parent_attributes as $tax => $attr) {
            if($attr->get_variation()) {
                $valid_taxonomies[] = $tax;
                foreach($attr->get_options() as $tid) {
                    $t = get_term($tid);
                    if($t && !is_wp_error($t)) $parent_terms_map[$tax][$t->slug] = $t->name;
                }
            }
        }
        
        $children = $product->get_children();
        if(empty($children)) return "No variations found.";

        $count_before = 0; 
        $count_after = 0; 
        $has_any = 'NO';
        $details = "";

        foreach($children as $cid) {
            $child = wc_get_product($cid);
            $meta = get_post_meta($cid);
            $changed = false;

            foreach($meta as $key => $val) {
                if(strpos($key, 'attribute_') === 0) {
                    $slug_val = $val[0];
                    $tax_name = substr($key, 10);
                    
                    // Case A: Wrong Taxonomy (Orphan)
                    if(!in_array($tax_name, $valid_taxonomies)) {
                        $count_before++;
                        foreach($valid_taxonomies as $valid_tax) {
                            // Exact
                            if(term_exists($slug_val, $valid_tax)) {
                                update_post_meta($cid, 'attribute_' . $valid_tax, $slug_val);
                                delete_post_meta($cid, $key);
                                $changed = true;
                                $details .= "Fixed {$slug_val} (Exact); ";
                                break;
                            }
                            // Fuzzy
                            if(isset($parent_terms_map[$valid_tax])) {
                                foreach($parent_terms_map[$valid_tax] as $p_slug => $p_name) {
                                    if(sanitize_title($slug_val) === sanitize_title($p_name) || $slug_val === $p_name) {
                                        update_post_meta($cid, 'attribute_' . $valid_tax, $p_slug);
                                        delete_post_meta($cid, $key);
                                        $changed = true;
                                        $details .= "Fixed {$slug_val} (Fuzzy); ";
                                        break 2; 
                                    }
                                }
                            }
                        }
                    } 
                    // Case B: Ghost Term (Correct Tax, Dead Slug)
                    else {
                        if(!term_exists($slug_val, $tax_name)) {
                            $count_before++;
                            if(isset($parent_terms_map[$tax_name])) {
                                foreach($parent_terms_map[$tax_name] as $p_slug => $p_name) {
                                    if(sanitize_title($slug_val) === sanitize_title($p_name) || $slug_val === $p_name) {
                                        update_post_meta($cid, $key, $p_slug); 
                                        $changed = true;
                                        $details .= "Fixed Ghost {$slug_val} -> {$p_slug}; ";
                                        break; 
                                    }
                                }
                            }
                        }
                    }
                }
            }
            
            foreach($valid_taxonomies as $vtax) {
                $val = get_post_meta($cid, 'attribute_' . $vtax, true);
                if(empty($val)) $has_any = 'YES';
            }

            if($changed) {
                $child->save();
                $count_after++;
            }
        }
        
        if($count_before > 0 || $has_any === 'YES') {
            return $this->write_log("REPAIR", $pid, "FIX ORPHANS/GHOSTS", $details ?: "No repairs possible", $count_before, $count_after, $has_any);
        }
        return "No repairs needed for Product #{$pid}.";
    }

    // --- BATCH & OTHER HANDLERS (Keep existing logic) ---
    public function ajax_run_repair_batch() {
        check_ajax_referer('wcam_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Permission denied');
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $limit = 1;
        $args = ['limit'=>$limit, 'page'=>$page, 'status'=>'any', 'type'=>['variable'], 'paginate'=>true];
        $results = wc_get_products($args);
        if($page > $results->max_num_pages) { wp_send_json_success(['done'=>true, 'count'=>0, 'log'=>'']); }
        $log = "";
        foreach($results->products as $product) { $log .= $this->repair_variable_product($product); }
        $this->cleanup_memory();
        wp_send_json_success(['done'=>($page >= $results->max_num_pages), 'count'=>count($results->products), 'total'=>$results->total, 'log'=>$log]);
    }

    public function ajax_run_batch() {
        check_ajax_referer('wcam_ajax_nonce', 'nonce');
        $target_slug = sanitize_text_field($_POST['target_attr']);
        $sources_slugs = isset($_POST['source_attrs']) ? array_map('sanitize_text_field', $_POST['source_attrs']) : [];
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $is_dry_run = isset($_POST['dry_run']) && $_POST['dry_run'] == '1';
        $target_tax = 'pa_' . $target_slug;
        $source_taxs = array_map(function($s){return 'pa_'.$s;}, $sources_slugs);
        $args = ['limit'=>10, 'page'=>$page, 'status'=>'any', 'type'=>['variable','simple'], 'paginate'=>true];
        $results = wc_get_products($args);
        if($page > $results->max_num_pages) { wp_send_json_success(['done'=>true, 'count'=>0, 'log'=>'']); }
        $log = "";
        foreach($results->products as $product) {
            $log .= $this->merge_product($product, $target_tax, $target_slug, $source_taxs, $is_dry_run);
        }
        $this->cleanup_memory();
        wp_send_json_success(['done'=>($page >= $results->max_num_pages), 'count'=>count($results->products), 'total'=>$results->total, 'log'=>$log]);
    }

    private function merge_product($product, $target_tax, $target_slug_raw, $source_taxs, $is_dry_run) {
        $attributes = $product->get_attributes();
        $pid = $product->get_id();
        $found_sources = [];
        $new_target_options = [];
        $is_variation_attr = false;
        $slug_map = [];

        foreach ($source_taxs as $source_tax) {
            if (isset($attributes[$source_tax])) {
                $found_sources[] = $source_tax;
                $attr = $attributes[$source_tax];
                if ($attr->get_variation()) $is_variation_attr = true;
                $term_ids = $attr->get_options();
                if (!empty($term_ids) && is_array($term_ids)) {
                    foreach ($term_ids as $term_id) {
                        $term_obj = get_term($term_id);
                        if ($term_obj && !is_wp_error($term_obj)) {
                            $tid = $this->get_target_term_id($term_obj, $target_tax, $is_dry_run);
                            if ($tid) {
                                $new_target_options[] = $tid;
                                if (!$is_dry_run) {
                                    $new_term = get_term($tid, $target_tax);
                                    if ($new_term && !is_wp_error($new_term)) {
                                        $slug_map[$source_tax][$term_obj->slug] = $new_term->slug;
                                    }
                                } else {
                                    $slug_map[$source_tax][$term_obj->slug] = $term_obj->slug;
                                }
                            }
                        }
                    }
                }
                if (!$is_dry_run) unset($attributes[$source_tax]);
            }
        }

        if (empty($found_sources)) return "";
        
        $vars_before = 0;
        $vars_after = 0;
        $has_any = 'NO';

        if (isset($attributes[$target_tax])) {
            $existing_opts = $attributes[$target_tax]->get_options();
            $new_target_options = array_unique(array_merge($existing_opts, $new_target_options));
        }

        if (!$is_dry_run) {
            $new_attr = new WC_Product_Attribute();
            $new_attr->set_id(wc_attribute_taxonomy_id_by_name($target_slug_raw));
            $new_attr->set_name($target_tax);
            $new_attr->set_options($new_target_options);
            $new_attr->set_position(0);
            $new_attr->set_visible(true);
            $new_attr->set_variation($is_variation_attr || (isset($attributes[$target_tax]) && $attributes[$target_tax]->get_variation()));
            $attributes[$target_tax] = $new_attr;
            $product->set_attributes($attributes);
            $product->save();
        }

        if ($product->is_type('variable') && $is_variation_attr) {
            $variations = $product->get_children();
            foreach ($variations as $vid) {
                $variation = wc_get_product($vid);
                if (!$variation) continue;
                $var_attrs = $variation->get_attributes();
                $changed = false;
                
                foreach($found_sources as $st) {
                    if(isset($var_attrs[$st])) $vars_before++;
                }

                foreach ($found_sources as $src_tax) {
                    if (isset($var_attrs[$src_tax])) {
                        $old_slug = $var_attrs[$src_tax];
                        $new_slug = '';
                        if (isset($slug_map[$src_tax][$old_slug])) {
                            $new_slug = $slug_map[$src_tax][$old_slug];
                        } else {
                            $new_slug = $this->get_mapped_slug($old_slug, $src_tax, $target_tax, $is_dry_run);
                        }

                        if ($new_slug && !$is_dry_run) {
                            $variation->update_meta_data('attribute_' . $target_tax, $new_slug);
                            $variation->delete_meta_data('attribute_' . $src_tax);
                            $changed = true;
                        }
                    }
                }
                if ($changed && !$is_dry_run) $variation->save();
                
                if(!$is_dry_run) {
                    $new_meta = get_post_meta($vid, 'attribute_' . $target_tax, true);
                    if(!empty($new_meta)) $vars_after++;
                    else $has_any = 'YES'; 
                } else {
                    $vars_after = $vars_before; 
                }
            }
        }
        
        $mode = $is_dry_run ? "DRY" : "LIVE";
        return $this->write_log("MERGE", $pid, $mode, "Sources: " . implode(',', $found_sources), $vars_before, $vars_after, $has_any);
    }

    public function ajax_run_rescue() {
        check_ajax_referer('wcam_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Permission denied');
        $target_slug = sanitize_text_field($_POST['target_attr']);
        $target_tax = 'pa_' . $target_slug;
        $terms = get_terms(['taxonomy' => $target_tax, 'hide_empty' => false, 'number' => 0]);
        if (empty($terms) || is_wp_error($terms)) { wp_send_json_success(['done'=>true, 'count'=>0, 'total'=>0, 'log'=>"RESCUE | N/A | N/A | INFO | No terms found.\n"]); }
        $log = "";
        $processed_count = 0;
        foreach ($terms as $term) {
            $changed = false;
            if (is_numeric($term->name)) {
                $processed_count++;
                $original_id = intval($term->name);
                $original_term = get_term($original_id); 
                if ($original_term && !is_wp_error($original_term)) {
                    if(strpos($original_term->taxonomy, 'pa_') !== 0) {
                         $this->write_log("RESCUE", "N/A", "SKIP", "ID {$term->name} is not attribute");
                         continue;
                    }
                    $old_name = $original_term->name;
                    if (term_exists($old_name, $target_tax)) {
                        $correct_term = get_term_by('name', $old_name, $target_tax);
                        if ($correct_term) {
                            $this->merge_terms_and_fix_meta($term, $correct_term, $target_tax);
                            $log .= $this->write_log("RESCUE", "N/A", "MERGE", "ID {$term->name} -> {$correct_term->name}");
                            continue;
                        }
                    } else {
                        wp_update_term($term->term_id, $target_tax, ['name' => $old_name, 'slug' => sanitize_title($old_name)]);
                        $log .= $this->write_log("RESCUE", "N/A", "RENAME", "{$term->name} -> {$old_name}");
                        $changed = true;
                    }
                }
            }
            if($changed) $term = get_term($term->term_id, $target_tax);
            if(is_numeric($term->slug)) {
                $new_slug = sanitize_title($term->name);
                if(!is_numeric($new_slug) && $new_slug != $term->slug) {
                    wp_update_term($term->term_id, $target_tax, ['slug' => $new_slug]);
                    $log .= $this->write_log("RESCUE", "N/A", "FIX SLUG", "{$term->slug} -> {$new_slug}");
                }
            }
        }
        wp_send_json_success(['done'=>true, 'count'=>$processed_count, 'total'=>count($terms), 'log'=>$log]);
    }

    public function ajax_get_terms() {
        $tax = sanitize_text_field($_POST['taxonomy']);
        $terms = get_terms(['taxonomy' => $tax, 'hide_empty' => false]);
        wp_send_json_success($terms);
    }
    public function ajax_manual_merge() {
        check_ajax_referer('wcam_ajax_nonce', 'nonce');
        $tax = sanitize_text_field($_POST['taxonomy']);
        $from = get_term(intval($_POST['from_id']), $tax);
        $to = get_term(intval($_POST['to_id']), $tax);
        if(!$from || !$to) wp_send_json_error('Terms not found.');
        $this->merge_terms_and_fix_meta($from, $to, $tax);
        $this->write_log("MANUAL", "N/A", "MERGE", "{$from->name} -> {$to->name}");
        wp_send_json_success("Merged '{$from->name}' into '{$to->name}'");
    }
    private function merge_terms_and_fix_meta($bad_term, $correct_term, $taxonomy) {
        global $wpdb;
        $objects = get_objects_in_term($bad_term->term_id, $taxonomy);
        if (!empty($objects)) {
            foreach ($objects as $object_id) {
                wp_set_object_terms($object_id, $correct_term->term_id, $taxonomy, true);
            }
        }
        $meta_key = 'attribute_' . $taxonomy;
        $wpdb->update($wpdb->postmeta, ['meta_value' => $correct_term->slug], ['meta_key' => $meta_key, 'meta_value' => $bad_term->slug]);
        wp_delete_term($bad_term->term_id, $taxonomy);
    }
    private function get_target_term_id($source_term, $target_tax, $is_dry_run) {
        if ($is_dry_run) return 999;
        $name = $source_term->name;
        $existing = term_exists($name, $target_tax);
        if ($existing) return $existing['term_id'];
        $new = wp_insert_term($name, $target_tax, ['slug' => $source_term->slug]);
        if (!is_wp_error($new)) return $new['term_id'];
        $by_slug = get_term_by('slug', $source_term->slug, $target_tax);
        if ($by_slug) return $by_slug->term_id;
        return 0;
    }
    private function get_mapped_slug($old_slug, $source_tax, $target_tax, $is_dry_run) {
        if ($is_dry_run) return $old_slug;
        $term = get_term_by('slug', $old_slug, $source_tax);
        if (!$term) return '';
        $name = $term->name;
        $new_term = get_term_by('name', $name, $target_tax);
        return $new_term ? $new_term->slug : '';
    }
    private function cleanup_memory() {
        if (function_exists('wc_reset_loop')) wc_reset_loop();
        global $wpdb, $wp_object_cache;
        $wpdb->queries = [];
        if (is_object($wp_object_cache)) {
            $wp_object_cache->group_ops = [];
            $wp_object_cache->stats = [];
            $wp_object_cache->memcache = null;
            $wp_object_cache->cache = [];
        }
    }
}
new WC_Attribute_Merger();