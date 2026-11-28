<?php
$__favicon_is_root = (realpath(dirname($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__DIR__ . '/..'));
$__favicon_href = $__favicon_is_root ? 'img/bimb.jpeg' : '../img/bimb.jpeg';
?>
<link rel="icon" type="image/jpeg" href="<?php echo $__favicon_href; ?>">
<link rel="shortcut icon" type="image/jpeg" href="<?php echo $__favicon_href; ?>">
<link rel="apple-touch-icon" href="<?php echo $__favicon_href; ?>">
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-143WX2DMT4"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());

  gtag('config', 'G-143WX2DMT4');
</script>