<?php

/*
 * This file is part of Hashids.
 *
 * (c) Ivan Akimov <ivan@barreleye.com>
 * Adapted by Maru Amallo (amamarul) <ama_marul@hotmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Amamarul\Hashids\Support\Hashids;

/**
 * This is the hashids class.
 *
 * @author Ivan Akimov <ivan@barreleye.com>
 * @author Vincent Klaiber <hello@vinkla.com>
 */
class Hashids implements HashidsInterface
{
    /**
     * The seps divider.
     *
     * @var float
     */
    const SEP_DIV = 3.5;

    /**
     * The guard divider.
     *
     * @var float
     */
    const GUARD_DIV = 12;

    /**
     * The alphabet string.
     *
     * @var string
     */
    protected $alphabet;

    /**
     * The seps string.
     *
     * @var string
     */
    protected $seps = 'cfhistuCFHISTU';

    /**
     * The guards string.
     *
     * @var string
     */
    protected $guards;

    /**
     * The minimum hash length.
     *
     * @var int
     */
    protected $minHashLength;

    /**
     * The salt string.
     *
     * @var string
     */
    protected $salt;

    /**
     * The prefix string.
     *
     * @var string
     */
    protected $prefix;

    /**
     * Create a new hashids instance.
     *
     * @param string $salt
     * @param int $minHashLength
     * @param string $alphabet
     *
     * @throws \Hashids\HashidsException
     *
     * @return void
     */
    public function __construct($salt = '', $minHashLength = 0, $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890', $prefix = null)
    {
        $this->salt = $salt;
        $this->minHashLength = $minHashLength;
        $this->alphabet = implode('', array_unique(str_split($alphabet)));
        $this->prefix = $prefix;
        $this->prefixSeparator = config('hashids.prefix-separator');

        if (strlen($this->alphabet) < 10) {
            throw new HashidsException('Alphabet must contain at least 10 unique characters.');
        }

        if (strpos($this->alphabet, ' ') !== false) {
            throw new HashidsException('Alphabet can\'t contain spaces.');
        }

        $alphabetArray = str_split($this->alphabet);
        $sepsArray = str_split($this->seps);

        $this->seps = implode('', array_intersect($sepsArray, $alphabetArray));
        $this->alphabet = implode('', array_diff($alphabetArray, $sepsArray));
        $this->seps = $this->shuffle($this->seps, $this->salt);

        if (!$this->seps || (strlen($this->alphabet) / strlen($this->seps)) > self::SEP_DIV) {
            $sepsLength = (int) ceil(strlen($this->alphabet) / self::SEP_DIV);

            if ($sepsLength > strlen($this->seps)) {
                $diff = $sepsLength - strlen($this->seps);
                $this->seps .= substr($this->alphabet, 0, $diff);
                $this->alphabet = substr($this->alphabet, $diff);
            }
        }

        $this->alphabet = $this->shuffle($this->alphabet, $this->salt);
        $guardCount = (int) ceil(strlen($this->alphabet) / self::GUARD_DIV);

        if (strlen($this->alphabet) < 3) {
            $this->guards = substr($this->seps, 0, $guardCount);
            $this->seps = substr($this->seps, $guardCount);
        } else {
            $this->guards = substr($this->alphabet, 0, $guardCount);
            $this->alphabet = substr($this->alphabet, $guardCount);
        }
    }

    /**
     * Encode parameters to generate a hash.
     *
     * @return string
     */
    public function encode()
    {
        $ret = '';
        $numbers = func_get_args();

        if (func_num_args() == 1 && is_array(func_get_arg(0))) {
            $numbers = $numbers[0];
        }

        if (!$numbers) {
            if ($this->prefix !== null) {
                return $this->prefix . $this->prefixSeparator . $ret;
            }
            return $ret;
        }

        foreach ($numbers as $number) {
            $isNumber = ctype_digit((string) $number);

            if (!$isNumber) {
                if ($this->prefix !== null) {
                    return $this->prefix . $this->prefixSeparator . $ret;
                }
                return $ret;
            }
        }

        $alphabet = $this->alphabet;
        $numbersSize = count($numbers);
        $numbersHashInt = 0;

        foreach ($numbers as $i => $number) {
            $numbersHashInt += Math::intval(Math::mod($number, ($i + 100)));
        }

        $lottery = $ret = $alphabet[$numbersHashInt % strlen($alphabet)];
        foreach ($numbers as $i => $number) {
            $alphabet = $this->shuffle($alphabet, substr($lottery . $this->salt . $alphabet, 0, strlen($alphabet)));
            $ret .= $last = $this->hash($number, $alphabet);

            if ($i + 1 < $numbersSize) {
                $number %= (ord($last) + $i);
                $sepsIndex = Math::intval(Math::mod($number, strlen($this->seps)));
                $ret .= $this->seps[$sepsIndex];
            }
        }

        if (strlen($ret) < $this->minHashLength) {
            $guardIndex = ($numbersHashInt + ord($ret[0])) % strlen($this->guards);

            $guard = $this->guards[$guardIndex];
            $ret = $guard . $ret;

            if (strlen($ret) < $this->minHashLength) {
                $guardIndex = ($numbersHashInt + ord($ret[2])) % strlen($this->guards);
                $guard = $this->guards[$guardIndex];

                $ret .= $guard;
            }
        }

        $halfLength = (int) (strlen($alphabet) / 2);
        while (strlen($ret) < $this->minHashLength) {
            $alphabet = $this->shuffle($alphabet, $alphabet);
            $ret = substr($alphabet, $halfLength) . $ret . substr($alphabet, 0, $halfLength);

            $excess = strlen($ret) - $this->minHashLength;
            if ($excess > 0) {
                $ret = substr($ret, $excess / 2, $this->minHashLength);
            }
        }
        if ($this->prefix !== null) {
            return $this->prefix . $this->prefixSeparator . $ret;
        }
        return $ret;
    }

    /**
     * Decode a hash to the original parameter values.
     *
     * @param string $hash
     *
     * @return array
     */
    public function decode($hash)
    {
        if ($this->prefix !== null && $this->prefixSeparator) {
            $hash = explode($this->prefix . $this->prefixSeparator, $hash)[1];
        } else if ($this->prefix !== null) {
            $hash = substr($hash, strlen($this->prefix), strlen($hash));
        }
        $ret = [];

        if (!is_string($hash) || !($hash = trim($hash))) {
            return $ret;
        }

        $alphabet = $this->alphabet;

        $ret = [];

        $hashBreakdown = str_replace(str_split($this->guards), ' ', $hash);
        $hashArray = explode(' ', $hashBreakdown);

        $i = count($hashArray) == 3 || count($hashArray) == 2 ? 1 : 0;

        $hashBreakdown = $hashArray[$i];

        if (isset($hashBreakdown[0])) {
            $lottery = $hashBreakdown[0];
            $hashBreakdown = substr($hashBreakdown, 1);

            $hashBreakdown = str_replace(str_split($this->seps), ' ', $hashBreakdown);
            $hashArray = explode(' ', $hashBreakdown);

            foreach ($hashArray as $subHash) {
                $alphabet = $this->shuffle($alphabet, substr($lottery . $this->salt . $alphabet, 0, strlen($alphabet)));
                $result = $this->unhash($subHash, $alphabet);
                if (Math::comp($result, PHP_INT_MAX) <= 0) {
                    $ret[] = Math::intval($result);
                } else {
                    $ret[] = Math::strval($result);
                }
            }

            if ($this->prefix !== null) {
                if ($this->encode($ret) != $this->prefix . $this->prefixSeparator . $hash) {
                    $ret = [];
                }
            } else {
                if ($this->encode($ret) != $hash) {
                    $ret = [];
                }
            }
        }

        return $ret[0];
    }

    /**
     * Encode hexadecimal values and generate a hash string.
     *
     * @param string $str
     *
     * @return string
     */
    public function encodeHex($str)
    {
        if (!ctype_xdigit((string) $str)) {
            return '';
        }

        $numbers = trim(chunk_split($str, 12, ' '));
        $numbers = explode(' ', $numbers);

        foreach ($numbers as $i => $number) {
            $numbers[$i] = hexdec('1' . $number);
        }

        return call_user_func_array([$this, 'encode'], $numbers);
    }

    /**
     * Decode a hexadecimal hash.
     *
     * @param string $hash
     *
     * @return string
     */
    public function decodeHex($hash)
    {
        $ret = '';
        $numbers = $this->decode($hash);

        foreach ($numbers as $i => $number) {
            $ret .= substr(dechex($number), 1);
        }

        return $ret;
    }

    /**
     * Shuffle alphabet by given salt.
     *
     * @param string $alphabet
     * @param string $salt
     *
     * @return string
     */
    protected function shuffle($alphabet, $salt)
    {
        $saltLength = strlen($salt);

        if (!$saltLength) {
            return $alphabet;
        }

        for ($i = strlen($alphabet) - 1, $v = 0, $p = 0; $i > 0; $i--, $v++) {
            $v %= $saltLength;
            $p += $int = ord($salt[$v]);
            $j = ($int + $v + $p) % $i;

            $temp = $alphabet[$j];
            $alphabet[$j] = $alphabet[$i];
            $alphabet[$i] = $temp;
        }

        return $alphabet;
    }

    /**
     * Hash given input value.
     *
     * @param string $input
     * @param string $alphabet
     *
     * @return string
     */
    protected function hash($input, $alphabet)
    {
        $hash = '';
        $alphabetLength = strlen($alphabet);

        do {
            $hash = $alphabet[Math::intval(Math::mod($input, $alphabetLength))] . $hash;

            $input = Math::divide($input, $alphabetLength);
        } while (Math::comp(0, $input) != 0);

        return $hash;
    }

    /**
     * Unhash given input value.
     *
     * @param string $input
     * @param string $alphabet
     *
     * @return int
     */
    protected function unhash($input, $alphabet)
    {
        $number = 0;
        $inputLength = strlen($input);

        if ($inputLength && $alphabet) {
            $alphabetLength = strlen($alphabet);
            $inputChars = str_split($input);

            foreach ($inputChars as $char) {
                $position = strpos($alphabet, $char);
                $number = Math::multiply($number, $alphabetLength);
                $number = Math::add($number, $position);
            }
        }

        return $number;
    }
}
