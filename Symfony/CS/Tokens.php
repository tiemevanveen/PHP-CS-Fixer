<?php

/*
 * This file is part of the PHP CS utility.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Symfony\CS;

/**
 * Collection of code tokens.
 *
 * @author Dariusz Rumiński <dariusz.ruminski@gmail.com>
 */
class Tokens extends \SplFixedArray
{
    /**
     * Check if given tokens are equal.
     * If tokens are arrays, then only keys defined in second token are checked.
     *
     * @param  string|array $tokenA token element generated by token_get_all
     * @param  string|array $tokenB token element generated by token_get_all or only few keys of it
     * @return bool
     */
    public static function compare($tokenA, $tokenB)
    {
        $tokenAIsArray = is_array($tokenA);
        $tokenBIsArray = is_array($tokenB);

        if ($tokenAIsArray !== $tokenBIsArray) {
            return false;
        }

        if (!$tokenAIsArray) {
            return $tokenA === $tokenB;
        }

        foreach ($tokenB as $key => $val) {
            if ($tokenA[$key] !== $val) {
                return false;
            }
        }

        return true;
    }

    /**
     * Create token collection from array.
     *
     * @param  array  $array       the array to import
     * @param  bool   $saveIndexes save the numeric indexes used in the original array, default is yes
     * @return Tokens
     */
     public static function fromArray($array, $saveIndexes = null)
     {
        $tokens = new Tokens(count($array));

        if (null === $saveIndexes || $saveIndexes) {
            foreach ($array as $key => $val) {
                $tokens[$key] = $val;
            }

            return $tokens;
        }

        $index = 0;

        foreach ($array as $val) {
            $tokens[$index++] = $val;
        }

        return $tokens;
     }

    /**
     * Create token collection directly from code.
     *
     * @param  string $code PHP code
     * @return Tokens
     */
    public static function fromCode($code)
    {
        $tokens = token_get_all($code);

        foreach ($tokens as $index => $token) {
            $tokens[$index] = new Token($token);
        }

        return static::fromArray($tokens);
    }

    /**
     * Check whether passed method name is one of magic methods.
     *
     * @param string $content name of method
     *
     * @return bool is method a magical
     */
    public static function isMethodNameIsMagic($name)
    {
        static $magicMethods = array(
            '__construct', '__destruct', '__call', '__callStatic', '__get', '__set', '__isset', '__unset',
            '__sleep', '__wakeup', '__toString', '__invoke', '__set_state', '__clone',
        );

        return in_array($name, $magicMethods);
    }

    /**
     * Apply token attributes.
     * Token at given index is prepended by attributes.
     *
     * @param int   $index   token index
     * @param array $attribs array of token attributes
     */
    public function applyAttribs($index, $attribs)
    {
        $attribsString = '';

        foreach ($attribs as $attrib) {
            if ($attrib) {
                $attribsString .= $attrib.' ';
            }
        }

        $this[$index]->content = $attribsString.$this[$index]->content;
    }

    /**
     * Removes all the trailing whitespace.
     *
     * @param int $index
     */
    public function removeTrailingWhitespace($index)
    {
        if (isset($this[$index + 1]) && $this[$index + 1]->isWhitespace()) {
            $this[$index + 1]->clear();
        }
    }

    /**
     * Removes all the leading whitespace.
     *
     * @param int $index
     */
    public function removeLeadingWhitespace($index)
    {
        if (isset($this[$index - 1]) && $this[$index - 1]->isWhitespace()) {
            $this[$index - 1]->clear();
        }
    }

    /**
     * Generate code from tokens.
     *
     * @return string
     */
    public function generateCode()
    {
        $code = '';
        $this->rewind();

        foreach ($this as $token) {
            $code .= $token->content;
        }

        return $code;
    }

    /**
     * Get indexes of methods and properties in classy code (classes, interfaces and traits).
     */
    public function getClassyElements()
    {
        $this->rewind();

        $elements = array(
            'methods' => array(),
            'properties' => array(),
        );

        $inClass = false;
        $curlyBracesLevel = 0;
        $bracesLevel = 0;

        foreach ($this as $index => $token) {
            if (!$inClass) {
                $inClass = $token->isClassy();
                continue;
            }

            if ('(' === $token->content) {
                ++$bracesLevel;
                continue;
            }

            if (')' === $token->content) {
                --$bracesLevel;
                continue;
            }

            if ('{' === $token->content || $token->isGivenKind(array(T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, ))) {
                ++$curlyBracesLevel;
                continue;
            }

            if ('}' === $token->content) {
                --$curlyBracesLevel;

                if (0 === $curlyBracesLevel) {
                    $inClass = false;
                }

                continue;
            }

            if (1 !== $curlyBracesLevel || !$token->isArray()) {
                continue;
            }

            if (T_VARIABLE === $token->id && 0 === $bracesLevel) {
                $elements['properties'][$index] = $token;
                continue;
            }

            if (T_FUNCTION === $token->id) {
                $elements['methods'][$index] = $token;
            }
        }

        return $elements;
    }

    /**
     * Get closest sibling token of given kind.
     *
     * @param  string|array $index     token index
     * @param  int          $direction direction for looking, +1 or -1
     * @param  array        $tokens    possible tokens
     * @return string|array token
     */
    public function getTokenOfKindSibling($index, $direction, array $tokens = array())
    {
        while (true) {
            $index += $direction;

            if (!$this->offsetExists($index)) {
                return null;
            }

            $token = $this[$index];

            foreach ($tokens as $tokenKind) {
                if (static::compare($token->getInternalState(), $tokenKind)) {
                    return $token;
                }
            }
        }
    }

    /**
     * Get closest next token of given kind.
     * This method is shorthand for getTokenOfKindSibling method.
     *
     * @param  string|array $index  token index
     * @param  array        $tokens possible tokens
     * @return string|array token
     */
    public function getNextTokenOfKind($index, array $tokens = array())
    {
        return $this->getTokenOfKindSibling($index, 1, $tokens);
    }

    /**
     * Get closest previous token of given kind.
     * This method is shorthand for getTokenOfKindSibling method.
     *
     * @param  string|array $index  token index
     * @param  array        $tokens possible tokens
     * @return string|array token
     */
    public function getPrevTokenOfKind($index, array $tokens = array())
    {
        return $this->getTokenOfKindSibling($index, -1, $tokens);
    }

    /**
     * Get closest sibling token which is non whitespace.
     *
     * @param  string|array $index     token index
     * @param  int          $direction direction for looking, +1 or -1
     * @param  array        $opts      array of extra options for isWhitespace method
     * @return string|array token
     */
    public function getNonWhitespaceSibling($index, $direction, array $opts = array())
    {
        while (true) {
            $index += $direction;

            if (!$this->offsetExists($index)) {
                return null;
            }

            $token = $this[$index];

            if (!$token->isWhitespace($opts)) {
                return $token;
            }
        }
    }

    /**
     * Get closest next token which is non whitespace.
     * This method is shorthand for getNonWhitespaceSibling method.
     *
     * @param  string|array $index token index
     * @param  array        $opts  array of extra options for isWhitespace method
     * @return string|array token
     */
    public function getNextNonWhitespace($index, array $opts = array())
    {
        return $this->getNonWhitespaceSibling($index, 1, $opts);
    }

    /**
     * Get closest previous token which is non whitespace.
     * This method is shorthand for getNonWhitespaceSibling method.
     *
     * @param  string|array $index token index
     * @param  array        $opts  array of extra options for isWhitespace method
     * @return string|array token
     */
    public function getPrevNonWhitespace($index, array $opts = array())
    {
        return $this->getNonWhitespaceSibling($index, -1, $opts);
    }

    /**
     * Grab attributes before token at gixen index.
     * Grabbed attributes are cleared by overriding them with empty string and should be manually applied with applyTokenAttribs method.
     *
     * @param  int   $index           token index
     * @param  array $tokenAttribsMap token to attribute name map
     * @param  array $attribs         array of token attributes
     * @return array array of grabbed attributes
     */
    public function grabAttribsBeforeToken($index, $tokenAttribsMap, $attribs)
    {
        while (true) {
            $token = $this[--$index];

            if (!$token->isArray()) {
                if (in_array($token->content, array('{', '}', '(', ')', ))) {
                    break;
                }

                continue;
            }

            // if token is attribute
            if (array_key_exists($token->id, $tokenAttribsMap)) {
                // set token attribute if token map defines attribute name for token
                if ($tokenAttribsMap[$token->id]) {
                    $attribs[$tokenAttribsMap[$token->id]] = $token->content;
                }

                // clear the token and whitespaces after it
                $this[$index]->clear();
                $this[$index + 1]->clear();

                continue;
            }

            if ($token->isGivenKind(array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, ))) {
                continue;
            }

            break;
        }

        return $attribs;
    }

    /**
     * Grab attributes before method token at gixen index.
     * It's a shorthand for grabAttribsBeforeToken method.
     *
     * @param  int   $index token index
     * @return array array of grabbed attributes
     */
    public function grabAttribsBeforeMethodToken($index)
    {
        static $tokenAttribsMap = array(
            T_PRIVATE => 'visibility',
            T_PROTECTED => 'visibility',
            T_PUBLIC => 'visibility',
            T_ABSTRACT => 'abstract',
            T_FINAL => 'final',
            T_STATIC => 'static',
        );

        return $this->grabAttribsBeforeToken(
            $index,
            $tokenAttribsMap,
            array(
                'abstract' => '',
                'final' => '',
                'visibility' => 'public',
                'static' => '',
            )
        );
    }

    /**
     * Grab attributes before property token at gixen index.
     * It's a shorthand for grabAttribsBeforeToken method.
     *
     * @param  int   $index token index
     * @return array array of grabbed attributes
     */
    public function grabAttribsBeforePropertyToken($index)
    {
        static $tokenAttribsMap = array(
            T_VAR => null, // destroy T_VAR token!
            T_PRIVATE => 'visibility',
            T_PROTECTED => 'visibility',
            T_PUBLIC => 'visibility',
            T_STATIC => 'static',
        );

        return $this->grabAttribsBeforeToken(
            $index,
            $tokenAttribsMap,
            array(
                'visibility' => 'public',
                'static' => '',
            )
        );
    }
}
