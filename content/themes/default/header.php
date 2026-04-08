<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html(get_bloginfo('name')); ?><?php if (!is_front_page()) echo ' - ' . esc_html(get_the_title()); ?></title>
    <?php cr_head(); ?>
</head>
<body <?php body_class(); ?>>
<div class="site">

<header class="site-header">
    <div class="container">
        <div class="site-branding">
            <div class="site-title"><a href="<?php echo esc_url(CR_HOME_URL); ?>"><?php bloginfo('name'); ?></a></div>
            <div class="site-description"><?php bloginfo('description'); ?></div>
        </div>
        <nav class="main-nav">
            <ul>
                <li><a href="<?php echo esc_url(CR_HOME_URL); ?>">Home</a></li>
                <li><a href="<?php echo esc_url(CR_HOME_URL); ?>/sample-page/">Sample Page</a></li>
            </ul>
        </nav>
    </div>
</header>

<main class="site-content">
    <div class="container">
        <div class="content-area">
