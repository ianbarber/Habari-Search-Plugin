<?php if( isset($spelling) && strlen($spelling) > 0 ): ?>
<div class="spellcorrect">
	Did you mean:
	<a href="<?php echo Url::get("display_search", array("criteria" => $spelling)); ?>">
		<?php echo $spelling ?> 
	</a>
</div>
<?php endif; ?>