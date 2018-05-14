<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

class MediaCheck implements Rule
{
    private $type;

    public function __construct($type)
    {
        $this->type = $type;
    }

    public function passes($attribute, $value)
    {
        switch ($this->type) {
            case 'image':
                return in_array('thumbnail', $value['type']) &&
                    $this->countElementInArray($value['type'], 'thumbnail') == 1 &&
                    in_array('image', $value['type']) &&
                    $this->countElementInArray($value['type'], 'image') == 1;
                break;
            case 'gallery':
                return in_array('thumbnail', $value['type']) &&
                    $this->countElementInArray($value['type'], 'thumbnail') == 1 &&
                    in_array('image', $value['type']);
                break;
            case 'audio':
                return in_array('thumbnail', $value['type']) &&
                    $this->countElementInArray($value['type'], 'thumbnail') == 1 &&
                    in_array('audio', $value['type']) &&
                    $this->countElementInArray($value['type'], 'audio') == 1;
                break;
            case 'video':
                return in_array('thumbnail', $value['type']) &&
                    $this->countElementInArray($value['type'], 'thumbnail') == 1 &&
                    in_array('image', $value['type']) &&
                    $this->countElementInArray($value['type'], 'image') == 1 &&
                    in_array('video', $value['type']) &&
                    $this->countElementInArray($value['type'], 'video') == 1;
                break;
            case 'quote':
                return in_array('quote', $value['type']) &&
                    $this->countElementInArray($value['type'], 'quote') == 1 &&
                    in_array('quote', array_keys($value)) &&
                    in_array('quoted', array_keys($value));
                break;
            case 'link':
                return in_array('link', $value['type']) &&
                    $this->countElementInArray($value['type'], 'link') == 1;
                break;
            default:
                return in_array('thumbnail', $value['type']) &&
                    $this->countElementInArray($value['type'], 'thumbnail') == 1 &&
                    in_array('image', $value['type']) &&
                    $this->countElementInArray($value['type'], 'image') == 1;
                break;
        }
    }

    public function message()
    {
        return trans('validation.custom.mediaCheck');
    }

    private function countElementInArray(array $iterable, $key)
    {
        $filtered = array_filter($iterable, function($item) use ($key) {
            return $item == $key;
        });
        return count($filtered);
    }
}
