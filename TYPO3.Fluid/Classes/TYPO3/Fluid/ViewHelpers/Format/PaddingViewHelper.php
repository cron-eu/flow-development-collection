<?php
namespace TYPO3\Fluid\ViewHelpers\Format;

/*                                                                        *
 * This script belongs to the TYPO3 Flow package "TYPO3.Fluid".           *
 *                                                                        *
 * It is free software; you can redistribute it and/or modify it under    *
 * the terms of the GNU Lesser General Public License, either version 3   *
 * of the License, or (at your option) any later version.                 *
 *                                                                        *
 * The TYPO3 project - inspiring people to share!                         *
 *                                                                        */

use TYPO3\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3\Fluid\Core\ViewHelper\Facets\CompilableInterface;

/**
 * Formats a string using PHPs str_pad function.
 *
 * @see http://www.php.net/manual/en/function.str_pad.php
 *
 * = Examples =
 *
 * <code title="Defaults">
 * <f:format.padding padLength="10">TYPO3</f:format.padding>
 * </code>
 * <output>
 * TYPO3     (note the trailing whitespace)
 * <output>
 *
 * <code title="Specify padding string">
 * <f:format.padding padLength="10" padString="-=">TYPO3</f:format.padding>
 * </code>
 * <output>
 * TYPO3-=-=-
 * </output>
 *
 * <code title="Specify padding type">
 * <f:format.padding padLength="10" padString="-" padType="both">TYPO3</f:format.padding>
 * </code>
 * <output>
 * --TYPO3---
 * </output>
 *
 * @api
 */
class PaddingViewHelper extends AbstractViewHelper implements CompilableInterface {

	/**
	 * Pad a string to a certain length with another string
	 *
	 * @param integer $padLength Length of the resulting string. If the value of pad_length is negative or less than the length of the input string, no padding takes place.
	 * @param string $padString The padding string
	 * @param string $padType Append the padding at this site (Possible values: right,left,both. Default: right)
	 * @return string The formatted value
	 * @param string $value string to format
	 * @api
	 */
	public function render($padLength, $padString = ' ', $padType = 'right', $value = NULL) {
		return self::renderStatic(array('padLength' => $padLength, 'padString' => $padString, 'padType' => $padType, 'value' => $value), $this->buildRenderChildrenClosure(), $this->renderingContext);
	}

	/**
	 * Applies str_pad() on the specified value.
	 *
	 * @param array $arguments
	 * @param \Closure $renderChildrenClosure
	 * @param \TYPO3\Fluid\Core\Rendering\RenderingContextInterface $renderingContext
	 * @return string
	 */
	static public function renderStatic(array $arguments, \Closure $renderChildrenClosure, RenderingContextInterface $renderingContext) {
		$value = $arguments['value'];
		if ($value === NULL) {
			$value = $renderChildrenClosure();
		}
		$padTypes = array(
			'left' => STR_PAD_LEFT,
			'right' => STR_PAD_RIGHT,
			'both' => STR_PAD_BOTH
		);
		$padType = $arguments['padType'];
		if (!isset($padTypes[$padType])) {
			$padType = 'right';
		}

		return str_pad($value, $arguments['padLength'], $arguments['padString'], $padTypes[$padType]);
	}
}