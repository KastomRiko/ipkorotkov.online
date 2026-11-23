<?php
if (!defined('ABSPATH')) exit;
function korotkov_dark_setup(){
  load_theme_textdomain('korotkov-dark', get_template_directory().'/languages');
  add_theme_support('title-tag'); add_theme_support('post-thumbnails'); add_theme_support('automatic-feed-links');
  add_theme_support('html5', array('search-form','comment-form','comment-list','gallery','caption','style','script'));
  add_theme_support('custom-logo', array('height'=>80,'width'=>260,'flex-height'=>true,'flex-width'=>true));
  add_theme_support('responsive-embeds'); add_theme_support('align-wide');
  register_nav_menus(array('primary'=>__('Главное меню','korotkov-dark'),'footer'=>__('Меню в подвале','korotkov-dark')));
}
add_action('after_setup_theme','korotkov_dark_setup');
function korotkov_dark_assets(){
  wp_enqueue_style('korotkov-fonts','https://fonts.googleapis.com/css2?family=Onest:wght@400;600;700;800;900&family=Manrope:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;700&display=swap',array(),null);
  wp_enqueue_style('korotkov-dark-style',get_stylesheet_uri(),array('korotkov-fonts'),'1.0.2');
  wp_enqueue_script('korotkov-dark-script',get_template_directory_uri().'/assets/theme.js',array(), '1.0.2', true);
}
add_action('wp_enqueue_scripts','korotkov_dark_assets');
function korotkov_dark_excerpt_length($length){return 28;}
add_filter('excerpt_length','korotkov_dark_excerpt_length');
function korotkov_dark_excerpt_more(){return '…';}
add_filter('excerpt_more','korotkov_dark_excerpt_more');
function korotkov_dark_body_class($classes){$classes[]='korotkov-dark-site';return $classes;}
add_filter('body_class','korotkov_dark_body_class');
