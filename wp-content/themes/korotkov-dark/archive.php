<?php get_header(); ?>
<main class="kd-main kd-blog" id="content">
  <section class="kd-blog-hero kd-blog-hero-archive">
    <div class="kd-wrap">
      <span class="kd-eyebrow">Материалы по теме</span>
      <h1><?php the_archive_title(); ?></h1>
      <?php if(get_the_archive_description()): ?><div class="kd-blog-lead"><?php the_archive_description(); ?></div><?php else: ?><p class="kd-blog-lead">Статьи, заметки и практические разборы по выбранной теме.</p><?php endif; ?>
    </div>
  </section>
  <section class="kd-blog-list"><div class="kd-wrap">
    <?php if(have_posts()): ?>
      <div class="kd-blog-grid">
        <?php while(have_posts()): the_post(); ?>
          <article <?php post_class('kd-blog-card'); ?>>
            <div class="kd-blog-card-top"><span class="kd-blog-date"><?php echo esc_html(get_the_date('d.m.Y')); ?></span><span class="kd-blog-category"><?php the_category(' · '); ?></span></div>
            <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
            <p class="kd-blog-excerpt"><?php echo esc_html(wp_trim_words(get_the_excerpt(), 34, '…')); ?></p>
            <a class="kd-blog-read" href="<?php the_permalink(); ?>">Читать материал <span aria-hidden="true">→</span></a>
          </article>
        <?php endwhile; ?>
      </div>
      <div class="kd-pagination"><?php the_posts_pagination(array('mid_size'=>1,'prev_text'=>'← Назад','next_text'=>'Дальше →')); ?></div>
    <?php else: ?><p>Материалы по этой теме пока не опубликованы.</p><?php endif; ?>
  </div></section>
</main>
<?php get_footer(); ?>
