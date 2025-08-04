<?php
/*
Plugin Name: Product Importer
Description: If a product already exists (based on a unique identifier like product title or ID), it updates the price and stock instead of creating a duplicate.
Version: 1.1
Author: Subhamay Aditya
*/

if (!defined('ABSPATH')) exit;

if (is_admin()) {
    add_action('admin_menu', 'products_plugin_menu');
}

function products_plugin_menu()
{
    add_menu_page(
        'Import DummyJSON Products',
        'Product Importer Plugin',
        'manage_options',
        'dummyjson-import',
        'myplugin_import_page'
    );
}

function myplugin_import_page()
{
    echo '<div class="wrap"><h1>Import DummyJSON Products</h1>';
    if (isset($_POST['djpi_import_products'])) {
        importproducts_api_func();
    }
    echo '<form method="post">
        <input type="submit" name="djpi_import_products" class="button button-primary" value="Import Products">
    </form></div>';
}

function importproducts_api_func()
{
    $response = wp_remote_get('https://dummyjson.com/products');

    if (is_wp_error($response)) {
        echo '<p style="color:red;">Failed to fetch products from API.</p>';
        return;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body);

    if (empty($data->products)) {
        echo '<p style="color:red;">No products found.</p>';
        return;
    }

    foreach ($data->products as $product_data) {
        $sku = 'dummy-' . $product_data->id; // Custom SKU with prefix
        $title = wp_strip_all_tags($product_data->title);

        // Check if product exists by SKU
        $existing_id = wc_get_product_id_by_sku($sku);

        // If not found by SKU, check by title
        if (!$existing_id) {
            $existing_id = djpi_get_product_id_by_title($title);
        }


        //  if ($existing_id) {
        //     // Update existing product
        //     $product = wc_get_product($existing_id);
        //     $product->set_price($product_data->price);
        //     $product->set_regular_price($product_data->price);
        //     $product->set_stock_quantity($product_data->stock);
        //     $product->save();
        // }


        if ($existing_id) {
            // Update existing product
            $product = wc_get_product($existing_id);

            $existing_stock = $product->get_stock_quantity();
            $existing_price = $product->get_regular_price();

            $new_stock = $existing_stock + $product_data->stock;
            $new_price = $existing_price + $product_data->price;

            $product->set_stock_quantity($new_stock);
            $product->set_price($new_price);
            $product->set_regular_price($new_price);
            $product->save();
        } else {
            //  Create new product
            $post_id = wp_insert_post([
                'post_title' => $title,
                'post_content' => $product_data->description,
                'post_status' => 'publish',
                'post_type' => 'product',
            ]);

            if ($post_id) {
                wp_set_object_terms($post_id, 'simple', 'product_type');

                update_post_meta($post_id, '_regular_price', $product_data->price);
                update_post_meta($post_id, '_price', $product_data->price);
                update_post_meta($post_id, '_stock', $product_data->stock);
                update_post_meta($post_id, '_stock_status', $product_data->stock > 0 ? 'instock' : 'outofstock');
                update_post_meta($post_id, '_sku', $sku);
                update_post_meta($post_id, '_manage_stock', 'yes');

                // Optional: Set product thumbnail
                if (!empty($product_data->thumbnail)) {
                    djpi_set_product_image($post_id, $product_data->thumbnail);
                }
            }
        }
    }

    echo '<script>
        document.addEventListener("DOMContentLoaded", function() {
            Swal.fire({
                title: "Products imported successfully",
                icon: "success"
            });
        });
    </script>';
}

// Helper: Get Product by Title
function djpi_get_product_id_by_title($title)
{
    global $wpdb;
    return $wpdb->get_var($wpdb->prepare("
        SELECT ID FROM $wpdb->posts
        WHERE post_title = %s AND post_type = 'product' AND post_status = 'publish'
        LIMIT 1
    ", $title));
}

// Set Product Image
function djpi_set_product_image($post_id, $image_url)
{
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');

    $image_id = media_sideload_image($image_url, $post_id, null, 'id');
    if (!is_wp_error($image_id)) {
        set_post_thumbnail($post_id, $image_id);
    }
}

//  Enqueue SweetAlert2
add_action('admin_enqueue_scripts', 'djpi_load_sweetalert');
function djpi_load_sweetalert($hook)
{
    if ($hook !== 'toplevel_page_dummyjson-import') return;
    wp_enqueue_script('sweetalert2', 'https://cdn.jsdelivr.net/npm/sweetalert2@11', [], null, true);
}

 