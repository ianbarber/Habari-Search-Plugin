<?php if( isset($similar) && count($similar) > 0 ): ?>
<div class="similar">
	<h4>Similar Posts to <?php echo $base_post->title; ?></h4>
	<ul>
		<?php foreach($similar as $similar_post): ?>
			<li>
				<a href="<?php echo URL::get("display_post", array("slug" => $similar_post->slug)); ?>">
					<?php echo $similar_post->title; ?>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
</div>
<?php endif; ?>