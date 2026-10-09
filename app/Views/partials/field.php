<?php
/* Petra — développé par BELEJ-CONSULTING. */
/**
 * Champ de formulaire générique.
 * @var string $name  @var string $labelKey  @var mixed $value
 * @var string $type (text|number|date|email|password|textarea|select) @var array $options [value => label]
 * @var bool $required  @var string|null $hint  @var array $attrs attributs HTML supplémentaires
 */
$type ??= 'text';
$required ??= false;
$options ??= [];
$attrs ??= [];
$hint ??= null;
$class ??= '';
$error = field_error($name);
$fieldId = 'f-' . str_replace(['[', ']'], ['-', ''], $name);
$attrHtml = '';
foreach ($attrs as $attr => $attrValue) {
    $attrHtml .= ' ' . e($attr) . '="' . e($attrValue) . '"';
}
$common = 'id="' . e($fieldId) . '" name="' . e($name) . '" class="input"' . ($required ? ' required' : '')
    . ($error ? ' aria-invalid="true" aria-describedby="' . e($fieldId) . '-err"' : '') . $attrHtml;
?>
<div class="field <?= e($class) ?>">
    <label for="<?= e($fieldId) ?>" class="<?= $required ? 'required' : '' ?>"><?= e(t($labelKey)) ?></label>
    <?php if ($type === 'textarea'): ?>
        <textarea <?= $common ?>><?= e($value) ?></textarea>
    <?php elseif ($type === 'select'): ?>
        <select <?= $common ?>>
            <?php foreach ($options as $optValue => $optLabel): ?>
                <option value="<?= e($optValue) ?>"<?= (string) $optValue === (string) $value ? ' selected' : '' ?>><?= e($optLabel) ?></option>
            <?php endforeach; ?>
        </select>
    <?php else: ?>
        <input type="<?= e($type) ?>" value="<?= e($value) ?>" <?= $common ?>>
    <?php endif; ?>
    <?php if ($hint): ?><span class="hint"><?= e($hint) ?></span><?php endif; ?>
    <?php if ($error): ?><span class="error" id="<?= e($fieldId) ?>-err"><?= e($error) ?></span><?php endif; ?>
</div>
