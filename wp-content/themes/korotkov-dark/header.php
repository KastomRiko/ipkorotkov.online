<!doctype html>
<html <?php language_attributes(); ?>>
<head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="icon" type="image/png" sizes="512x512" href="https://ipkorotkov.online/wp-content/uploads/2026/11/browser_1217683.png"><?php wp_head(); ?></head>
<body <?php body_class(); ?>><?php wp_body_open(); ?>
<a class="screen-reader-text" href="#content">Перейти к содержимому</a>
<header class="kd-header"><div class="kd-wrap kd-headrow">
<a class="kd-brand" href="<?php echo esc_url(home_url('/')); ?>" aria-label="На главную"><span class="kd-mark">КК</span><span><?php echo esc_html(get_bloginfo('name') ?: 'Константин Коротков'); ?><small>РАЗРАБОТКА · АВТОМАТИЗАЦИЯ · IT</small></span></a>
<button class="kd-menu-toggle" type="button" aria-expanded="false" aria-controls="primary-menu">Меню ☰</button>
<nav class="kd-nav" id="primary-menu" aria-label="Главное меню"><?php if(has_nav_menu('primary')){wp_nav_menu(array('theme_location'=>'primary','container'=>false,'depth'=>2,'fallback_cb'=>false));}else{ ?><a href="<?php echo esc_url(home_url('/')); ?>">Главная</a><a href="<?php echo esc_url(home_url('/services/')); ?>">Услуги</a><a href="<?php echo esc_url(home_url('/about/')); ?>">Обо мне</a><a href="<?php echo esc_url(home_url('/blog/')); ?>">Блог</a><a href="<?php echo esc_url(home_url('/contacts/')); ?>">Контакты</a><?php } ?></nav>
<a class="kd-headcta" href="tel:+79641519362">Обсудить задачу ↗</a>
</div></header>
