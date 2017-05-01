
{extends file="helpers/form/form.tpl"}

{block name="input"}
	{if $input.type == 'products'}
		<table id="{$input.name}">
			<tr>
				<th></th>
				<th>ID</th>
				<th width="80%">{l s='Product Name'}</th>
			</tr>
			{foreach $input.values as $value}
				<tr>
					<td>
						<input type="checkbox" name="{$input.name}[]" value="{$value.id_product}" 
						{if isset($value.selected) && $value.selected == 1} checked {/if} />
					</td>
					<td>{$value.id_product}</td>
					<td width="80%">{$value.name}</td>
				</tr>
			{/foreach}
		</table>
    {elseif $input.type == 'switch' && $smarty.const._PS_VERSION_|@addcslashes:'\'' < '1.6'}
		{foreach $input.values as $value}
			<input type="radio" name="{$input.name}" id="{$value.id}" value="{$value.value|escape:'html':'UTF-8'}"
					{if $fields_value[$input.name] == $value.value}checked="checked"{/if}
					{if isset($input.disabled) && $input.disabled}disabled="disabled"{/if} />
			<label class="t" for="{$value.id}">
			 {if isset($input.is_bool) && $input.is_bool == true}
				{if $value.value == 1}
					<img src="../img/admin/enabled.gif" alt="{$value.label}" title="{$value.label}" />
				{else}
					<img src="../img/admin/disabled.gif" alt="{$value.label}" title="{$value.label}" />
				{/if}
			 {else}
				{$value.label}
			 {/if}
			</label>
			{if isset($input.br) && $input.br}<br />{/if}
			{if isset($value.p) && $value.p}<p>{$value.p}</p>{/if}
		{/foreach}
	{else}
		{$smarty.block.parent}
    {/if}

{/block}
