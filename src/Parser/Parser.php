<?php

namespace Kint\Parser;

use ArrayObject;
use Exception;
use Kint\Object\BasicObject;
use Kint\Object\BlobObject;
use Kint\Object\InstanceObject;
use Kint\Object\Representation\Representation;
use Kint\Object\ResourceObject;
use ReflectionObject;

class Parser
{
    public $caller_class;
    public $max_depth;

    private $marker;
    private $object_hashes = array();
    private $parse_break = false;
    private $plugins = array();

    /**
     * Plugin triggers.
     *
     * These are constants indicating trigger points for plugins
     *
     * BEGIN: Before normal parsing
     * SUCCESS: After successful parsing
     * RECURSION: After parsing cancelled by recursion
     * DEPTH_LIMIT: After parsing cancelled by depth limit
     * COMPLETE: SUCCESS | RECURSION | DEPTH_LIMIT
     *
     * While a plugin's getTriggers may return any of these
     */
    const TRIGGER_NONE = 0;
    const TRIGGER_BEGIN = 1;
    const TRIGGER_SUCCESS = 2;
    const TRIGGER_RECURSION = 4;
    const TRIGGER_DEPTH_LIMIT = 8;
    const TRIGGER_COMPLETE = 14;

    public function __construct($max_depth = false, $c = null)
    {
        $this->marker = uniqid("kint\0", true);
        $this->caller_class = $c;
        $this->max_depth = $max_depth;
    }

    public function parse(&$var, BasicObject $o)
    {
        $o->type = strtolower(gettype($var));

        if (!$this->applyPlugins($var, $o, self::TRIGGER_BEGIN)) {
            return $o;
        }

        switch ($o->type) {
            case 'array':
                return $this->parseArray($var, $o);
            case 'boolean':
            case 'double':
            case 'integer':
            case 'null':
                return $this->parseGeneric($var, $o);
            case 'object':
                return $this->parseObject($var, $o);
            case 'resource':
                return $this->parseResource($var, $o);
            case 'string':
                return $this->parseString($var, $o);
            default:
                return $this->parseUnknown($var, $o);
        }
    }

    private function parseGeneric(&$var, BasicObject $o)
    {
        $rep = new Representation('Contents');
        $rep->contents = $var;
        $rep->implicit_label = true;
        $o->addRepresentation($rep);

        $this->applyPlugins($var, $o, self::TRIGGER_SUCCESS);

        return $o;
    }

    private function parseString(&$var, BasicObject $o)
    {
        $string = $o->transplant(new BlobObject());
        $string->encoding = BlobObject::detectEncoding($var);
        $string->size = BlobObject::strlen($var, $string->encoding);

        $rep = new Representation('Contents');
        $rep->contents = $var;
        $rep->implicit_label = true;

        $string->addRepresentation($rep);

        $this->applyPlugins($var, $string, self::TRIGGER_SUCCESS);

        return $string;
    }

    private function parseArray(array &$var, BasicObject $o)
    {
        $array = $o->transplant(new BasicObject());
        $array->size = count($var);

        if (isset($var[$this->marker])) {
            --$array->size;
            $array->hints[] = 'recursion';

            $this->applyPlugins($var, $array, self::TRIGGER_RECURSION);

            return $array;
        }

        $rep = new Representation('Contents');
        $rep->implicit_label = true;
        $array->addRepresentation($rep);

        if ($array->size) {
            if ($this->max_depth && $o->depth >= $this->max_depth) {
                $array->hints[] = 'depth_limit';

                $this->applyPlugins($var, $array, self::TRIGGER_DEPTH_LIMIT);

                return $array;
            }

            $copy = array_values($var);

            // It's really really hard to access numeric string keys in arrays,
            // and it's really really hard to access integer properties in
            // objects, so we just use array_values and index by counter to get
            // at it reliably for reference testing. This also affects access
            // paths since it's pretty much impossible to access these things
            // without complicated stuff you should never need to do.
            $i = 0;

            // Set the marker for recursion
            $var[$this->marker] = $array->depth;

            foreach ($var as $key => &$val) {
                if ($key === $this->marker) {
                    continue;
                }

                $child = new BasicObject();
                $child->name = $key;
                $child->depth = $array->depth + 1;
                $child->access = BasicObject::ACCESS_NONE;
                $child->operator = BasicObject::OPERATOR_ARRAY;

                if ($array->access_path !== null) {
                    if (is_string($key) && (string) (int) $key === $key) {
                        $child->access_path = 'array_values('.$array->access_path.')['.$i.']';
                    } else {
                        $child->access_path = $array->access_path.'['.var_export($key, true).']';
                    }
                }

                $stash = $val;
                $copy[$i] = $this->marker;
                if ($val === $this->marker) {
                    $child->reference = true;
                    $val = $stash;
                }

                $rep->contents[] = $this->parse($val, $child);
                ++$i;
            }

            $this->applyPlugins($var, $array, self::TRIGGER_SUCCESS);
            unset($var[$this->marker]);

            return $array;
        } else {
            $this->applyPlugins($var, $array, self::TRIGGER_SUCCESS);

            return $array;
        }
    }

    private function parseObject(&$var, BasicObject $o)
    {
        $hash = spl_object_hash($var);
        $values = (array) $var;

        $object = $o->transplant(new InstanceObject());
        $object->classname = get_class($var);
        $object->hash = $hash;
        $object->size = count($values);

        if (isset($this->object_hashes[$hash])) {
            $object->hints[] = 'recursion';

            $this->applyPlugins($var, $object, self::TRIGGER_RECURSION);

            return $object;
        }

        $this->object_hashes[$hash] = $object;

        if ($this->max_depth && $o->depth >= $this->max_depth) {
            $object->hints[] = 'depth_limit';

            $this->applyPlugins($var, $object, self::TRIGGER_DEPTH_LIMIT);
            unset($this->object_hashes[$hash]);

            return $object;
        }

        // ArrayObject (and maybe ArrayIterator, did not try yet) unsurprisingly
        // consist of mainly dark magic. What bothers me most, var_dump sees no
        // problem with it, and ArrayObject also uses a custom, undocumented
        // serialize function, so you can see the properties in internal functions,
        // but can never iterate some of them if the flags are not STD_PROP_LIST. Fun stuff.
        if ($var instanceof ArrayObject) {
            $ArrayObject_flags_stash = $var->getFlags();
            $var->setFlags(ArrayObject::STD_PROP_LIST);
        }

        $reflector = new ReflectionObject($var);

        if ($reflector->isUserDefined()) {
            $object->filename = $reflector->getFileName();
            $object->startline = $reflector->getStartLine();
        }

        $rep = new Representation('Properties');

        $copy = array_values($values);

        $i = 0;

        // Reflection will not show parent classes private properties, and if a
        // property was unset it will happly trigger a notice looking for it.
        foreach ($values as $key => &$val) {
            // Casting object to array:
            // private properties show in the form "\0$owner_class_name\0$property_name";
            // protected properties show in the form "\0*\0$property_name";
            // public properties show in the form "$property_name";
            // http://www.php.net/manual/en/language.types.array.php#language.types.array.casting

            $child = new BasicObject();
            $child->depth = $object->depth + 1;
            $child->owner_class = $object->classname;
            $child->operator = BasicObject::OPERATOR_OBJECT;
            $child->access = BasicObject::ACCESS_PUBLIC;

            $split_key = explode("\0", $key, 3);

            if (count($split_key) === 3 && $split_key[0] === '') {
                $child->name = $split_key[2];
                if ($split_key[1] === '*') {
                    $child->access = BasicObject::ACCESS_PROTECTED;
                } else {
                    $child->access = BasicObject::ACCESS_PRIVATE;
                    $child->owner_class = $split_key[1];
                }
            } elseif (KINT_PHP72) {
                $child->name = (string) $key;
            } else {
                $child->name = $key;
            }

            if ($this->childHasPath($object, $child)) {
                $child->access_path = $object->access_path;

                if (!KINT_PHP72 && is_int($child->name)) {
                    $child->access_path = 'array_values((array) '.$child->access_path.')['.$i.']';
                } elseif (preg_match('/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/', $child->name)) {
                    $child->access_path .= '->'.$child->name;
                } else {
                    $child->access_path .= '->{'.var_export((string) $child->name, true).'}';
                }
            }

            $stash = $val;
            $copy[$i] = $this->marker;
            if ($val === $this->marker) {
                $child->reference = true;
                $val = $stash;
            }

            $rep->contents[] = $this->parse($val, $child);
            ++$i;
        }

        if (isset($ArrayObject_flags_stash)) {
            $var->setFlags($ArrayObject_flags_stash);
        }

        usort($rep->contents, array('Kint\\Parser\\Parser', 'sortObjectProperties'));

        $object->addRepresentation($rep);
        $this->applyPlugins($var, $object, self::TRIGGER_SUCCESS);
        unset($this->object_hashes[$hash]);

        return $object;
    }

    private function parseResource(&$var, BasicObject $o)
    {
        $resource = $o->transplant(new ResourceObject());
        $resource->resource_type = get_resource_type($var);

        $this->applyPlugins($var, $resource, self::TRIGGER_SUCCESS);

        return $resource;
    }

    private function parseUnknown(&$var, BasicObject $o)
    {
        $o->type = 'unknown';
        $this->applyPlugins($var, $o, self::TRIGGER_SUCCESS);

        return $o;
    }

    public function addPlugin(Plugin $p)
    {
        if (!$types = $p->getTypes()) {
            return false;
        }

        if (!$triggers = $p->getTriggers()) {
            return false;
        }

        $p->setParser($this);

        foreach ($types as $type) {
            if (!isset($this->plugins[$type])) {
                $this->plugins[$type] = array(
                    self::TRIGGER_BEGIN => array(),
                    self::TRIGGER_SUCCESS => array(),
                    self::TRIGGER_RECURSION => array(),
                    self::TRIGGER_DEPTH_LIMIT => array(),
                );
            }

            foreach ($this->plugins[$type] as $trigger => &$pool) {
                if ($triggers & $trigger) {
                    $pool[] = $p;
                }
            }
        }

        return true;
    }

    public function clearPlugins()
    {
        $this->plugins = array();
    }

    /**
     * Applies plugins for an object type.
     *
     * @param mixed       &$var    variable
     * @param BasicObject &$o      Kint object parsed so far
     * @param int         $trigger The trigger to check for the plugins
     *
     * @return bool Continue parsing
     */
    private function applyPlugins(&$var, BasicObject &$o, $trigger)
    {
        $break_stash = $this->parse_break;
        $this->parse_break = false;

        $plugins = array();

        if (isset($this->plugins[$o->type][$trigger])) {
            $plugins = $this->plugins[$o->type][$trigger];
        }

        foreach ($plugins as $plugin) {
            try {
                $plugin->parse($var, $o, $trigger);
            } catch (Exception $e) {
                trigger_error(
                    'An exception ('.get_class($e).') was thrown in '.$e->getFile().' on line '.$e->getLine().' while executing Kint Parser Plugin "'.get_class($plugin).'". Error message: '.$e->getMessage(),
                    E_USER_WARNING
                );
            }

            if ($this->parse_break) {
                $this->parse_break = $break_stash;

                return false;
            }
        }

        $this->parse_break = $break_stash;

        return true;
    }

    public function haltParse()
    {
        $this->parse_break = true;
    }

    public function childHasPath(InstanceObject $parent, BasicObject $child)
    {
        if ($parent->type === 'object' && ($parent->access_path !== null || $child->static || $child->const)) {
            if ($child->access === BasicObject::ACCESS_PUBLIC) {
                return true;
            } elseif ($child->access === BasicObject::ACCESS_PRIVATE && $this->caller_class) {
                if ($this->caller_class === $child->owner_class) {
                    return true;
                }
            } elseif ($child->access === BasicObject::ACCESS_PROTECTED && $this->caller_class) {
                if (is_a($this->caller_class, $child->owner_class, true)) {
                    return true;
                } elseif (is_a($child->owner_class, $this->caller_class, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Returns an array without the recursion marker in it.
     *
     * DO NOT pass an array that has had it's marker removed back
     * into the parser, it will result in an extra recursion
     *
     * @param array $array Array potentially containing a recursion marker
     *
     * @return array Array with recursion marker removed
     */
    public function getCleanArray(array $array)
    {
        unset($array[$this->marker]);

        return $array;
    }

    private static function sortObjectProperties(BasicObject $a, BasicObject $b)
    {
        $sort = BasicObject::sortByAccess($a, $b);
        if ($sort) {
            return $sort;
        }

        $sort = BasicObject::sortByName($a, $b);
        if ($sort) {
            return $sort;
        }

        return InstanceObject::sortByHierarchy($a->owner_class, $b->owner_class);
    }
}
