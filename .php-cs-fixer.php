<?php

$finder = PhpCsFixer\Finder::create()
	->in(__DIR__)
	->exclude(['.claude', '.git', 'vendor'])
	->name('*.php')
	->name('*.phtml');

return (new PhpCsFixer\Config())
	->setRiskyAllowed(false)
	->setIndent("\t")
	->setRules([
		'@PSR12' => true,
		'braces_position' => [
			'classes_opening_brace' => 'same_line',
			'functions_opening_brace' => 'same_line',
		],
		'concat_space' => ['spacing' => 'one'],
		'no_trailing_whitespace' => true,
		'single_blank_line_at_eof' => true,
	])
	->setFinder($finder);
