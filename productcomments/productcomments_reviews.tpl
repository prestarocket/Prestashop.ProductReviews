
<div style="clear: both;"></div>
<div class="comments_note">	
	<div class="star_content clearfix">
	{section name="i" start=0 loop=5 step=1}
		{if $averageTotal le $smarty.section.i.index}
			<i class="fa fa-star" aria-hidden="true"></i>
		{else}
			<i class="fa fa-star" aria-hidden="true" style="color:#FFCC00;"></i>
		{/if}
	{/section}
	</div>
	<span class="span-review-main">{l s='%s Review(s)'|sprintf:$averageTotal mod='productcomments'}&nbsp</span>
</div>
<div style="clear: both;"></div>