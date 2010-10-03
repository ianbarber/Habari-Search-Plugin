<?php if( isset($spelling) && strlen($spelling) > 0 ): ?>
<div class="spellcorrect">
	Did you mean:
	<a href="/search?criteria=<?php echo urlencode($spelling) ?>">
		<?php echo $spelling ?> 
	</a>
</div>
<?php endif; ?>