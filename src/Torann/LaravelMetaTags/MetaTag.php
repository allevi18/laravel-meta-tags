<?php

namespace Torann\LaravelMetaTags;

use Illuminate\Support\Arr;
use Illuminate\Http\Request;

class MetaTag
{
    /**
     * Instance of request
     *
     * @var \Illuminate\Http\Request
     */
    private $request;

    /**
     * @var array
     */
    private $config = [];

    /**
     * Locale default for app.
     *
     * @var string
     */
    private $default_locale = '';

    /**
     * @var array
     */
    private $metas = [];

    /**
     * @var string
     */
    private $title;

    /**
     * OpenGraph elements
     *
     * @var array
     */
    private $og = [
        'title', 'description', 'type', 'image', 'url', 'audio',
        'determiner', 'locale', 'site_name', 'video',
    ];

    /**
     * Twitter card elements
     *
     * @var array
     */
    private $twitter = [
        'card', 'site', 'title', 'description',
        'creator', 'image:src', 'domain',
    ];

    /**
     * @param \Illuminate\Http\Request $request
     * @param array                    $config
     * @param string                   $default_locale
     */
    public function __construct(Request $request, array $config = [], $default_locale = 'en')
    {
        $this->request = $request;
        $this->config = $config;

        // Set defaults
        $this->set('title', $this->config['title']);
        $this->set('url', $this->request->url());

        // Set default locale
        $this->default_locale = $default_locale;

        // Is locales a callback
        if (is_callable($this->config['locales'])) {
            $this->setLocales(call_user_func($this->config['locales']));
        }
    }

    /**
     * Set app support locales.
     *
     * @param array $locals
     */
    public function setLocales(array $locals = [])
    {
        $this->config['locales'] = $locals;
    }

    /**
     * @param string $key
     * @param string $default
     *
     * @return string
     */
    public function get($key, $default = null)
    {
        return Arr::get($this->metas, $key, $default);
    }

    /**
     * @param string $key
     * @param string $value
     *
     * @return string
     */
    public function set($key, $value = null)
    {
        $value = $this->fix($value);

        $method = 'set' . $key;

        if (method_exists($this, $method)) {
            return $this->$method($value);
        }

        return $this->metas[$key] = self::cut($value, $key);
    }

    /**
     * Create a tag based on the given key
     *
     * @param string $key
     * @param string $value
     *
     * @return string
     */
    public function tag($key, $value = '')
    {
        return $this->createTag([
            'name' => $key,
            'property' => $key,
            'content' => $value ?: Arr::get($this->metas, $key, ''),
        ]);
    }

    /**
     * Create canonical tags
     *
     * @return string
     */
    public function canonical()
    {
        $url = self::get('canonical');
        return $url ? "<link rel='canonical' href='$url'>" : null;
    }

    /**
     * Create open graph tags
     *
     * @return string
     */
    public function openGraph()
    {
        $html = [
            'url' => $this->createTag([
                'property' => 'og:url',
                'content' => $this->request->url(),
            ]),
        ];

        foreach ($this->og as $tag) {
            // Get value for tag, default to dynamically set value
            $value = Arr::get($this->config['open_graph'], $tag, $this->get($tag));

            if ($value) {
                $html[$tag] = $this->createTag([
                    'property' => "og:{$tag}",
                    'content' => $value,
                ]);
            }
        }

        return implode('', $html);
    }

    /**
     * Create Facebook app ID tag
     *
     * @return string
     */
    public function fbAppId()
    {
        return $this->createTag([
            'property' => 'fb:app_id',
            'content' => $this->get('fb:app_id'),
        ]);
    }

    /**
     * Create twitter card tags
     *
     * @return string
     */
    public function twitterCard()
    {
        $html = [];

        foreach ($this->twitter as $tag) {
            // Get value for tag, default to dynamically set value
            $value = Arr::get($this->config['twitter'], $tag, $this->get($tag));

            if ($value && ! isset($html[$tag])) {
                $html[$tag] = $this->createTag([
                    'name' => "twitter:{$tag}",
                    'content' => $value,
                ]);
            }
        }

        // Set image
        if (empty($html['image:src']) && $this->get('image')) {
            $html['image:src'] = $this->createTag([
                'name' => "twitter:image:src",
                'content' => $this->get('image'),
            ]);
        }

        // Set domain
        if (empty($html['domain'])) {
            $html['domain'] = $this->createTag([
                'name' => "twitter:domain",
                'content' => $this->request->getHttpHost(),
            ]);
        }

        return implode('', $html);
    }

    /**
     * @param string $value
     *
     * @return string
     */
    private function setTitle($value)
    {
        $title = $this->title;

        if ($title && $this->config['title_limit']) {
            $title = ' - ' . $title;
            $limit = $this->config['title_limit'] - strlen($title);
        } else {
            $limit = 'title';
        }

        return $this->metas['title'] = self::cut($value, $limit) . $title;
    }

    /**
     * Create meta tag from attributes
     *
     * @param array $values
     *
     * @return string
     */
    private function createTag(array $values)
    {
        $attributes = array_map(function ($key) use ($values) {
            $value = $this->fix($values[$key]);

            return "{$key}=\"{$value}\"";
        }, array_keys($values));

        $attributes = implode(' ', $attributes);

        return "<meta {$attributes}>\n    ";
    }

    /**
     * @param string $text
     *
     * @return string
     */
    private function fix($text)
    {
        $text = preg_replace('/<[^>]+>/', ' ', $text);
        $text = preg_replace('/[\r\n\s]+/', ' ', $text);

        return trim(str_replace('"', '&quot;', $text));
    }

    /**
     * @param string $text
     * @param string $key
     *
     * @return string
     */
    private function cut($text, $key)
    {
        if (is_string($key) && isset($this->config[$key . '_limit'])) {
            $limit = $this->config[$key . '_limit'];
        } else {
            if (is_integer($key)) {
                $limit = $key;
            } else {
                return $text;
            }
        }

        $length = strlen($text);

        if ($length <= (int) $limit) {
            return $text;
        }

        $text = substr($text, 0, ($limit -= 3));

        if ($space = strrpos($text, ' ')) {
            $text = substr($text, 0, $space);
        }

        return $text . '...';
    }

    /**
     * Returns an URL adapted to locale
     *
     * @param string $locale
     *
     * @return string
     */
    private function localizedURL($locale)
    {
        // Default language doesn't get a special subdomain
        $locale = ($locale !== $this->default_locale) ? strtolower($locale) . '.' : '';

        // URL elements
        $uri = $this->request->getRequestUri();
        $scheme = $this->request->getScheme();

        // Get host
        $array = explode('.', $this->request->getHttpHost());
        $host = (array_key_exists(count($array) - 2, $array) ? $array[count($array) - 2] : '') . '.' . $array[count($array) - 1];

        // Create URL from template
        $url = str_replace(
            ['[scheme]', '[locale]', '[host]', '[uri]'],
            [$scheme, $locale, $host, $uri],
            $this->config['locale_url']
        );

        return url($url);
    }
}
