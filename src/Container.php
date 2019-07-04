<?php
/**
 * The following code, none of which has BUG.
 *
 * @author: BD<liuxingwu@duoguan.com>
 * @date: 2019/7/3 15:11
 */
namespace xin\container;

use Closure;
use InvalidArgumentException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;
use think\Loader;

class Container implements ContainerInterface, \ArrayAccess{

	/**
	 * 实例列表
	 *
	 * @var array
	 */
	protected $instances = [];

	/**
	 * 绑定列表
	 *
	 * @var array
	 */
	protected $binds = [];

	/**
	 * 类映射
	 *
	 * @var array
	 */
	protected $mappings = [];

	/**
	 * Create an instance based on the identity
	 *
	 * @param string $id
	 * @param bool   $newInstance
	 * @return mixed
	 * @throws \xin\container\ContainerException
	 * @throws \xin\container\NotFoundException
	 */
	public function make($id, $newInstance = false){
		$vars = [];

		if(isset($this->binds[$id])){
			$concrete = $this->binds[$id];
			if($concrete instanceof Closure){
				$object = $this->invokeFunction($concrete, $vars);
			}else{
				$this->mappings[$id] = $concrete;
				return $concrete;
			}
		}else{
			$object = $this->invokeClass($id, $vars);
		}

		if(!$newInstance){
			$this->instances[$id] = $object;
		}

		return $object;
	}

	/**
	 * Finds an entry of the container by its identifier and returns it.
	 *
	 * @param string $id Identifier of the entry to look for.
	 * @return mixed Entry.
	 * @throws ContainerExceptionInterface Error while retrieving the entry.
	 * @throws NotFoundExceptionInterface  No entry was found for **this** identifier.
	 */
	public function get($id){
		$id = isset($this->mappings[$id]) ? $this->mappings[$id] : $id;

		if(isset($this->instances[$id])){
			return $this->instances[$id];
		}

		return $this->make($id);
	}

	/**
	 * Bind an identity to the container
	 *
	 * @param string $id Identifier of the entry to look for.
	 * @param        $concrete
	 */
	public function bind($id, $concrete){
		if(is_array($id)){
			$this->binds = array_merge($this->binds, $id);
		}elseif($concrete instanceof Closure){
			$this->binds[$id] = $concrete;
		}elseif(is_object($concrete)){
			if(isset($this->binds[$id])){
				$id = $this->binds[$id];
			}
			$this->instances[$id] = $concrete;
		}else{
			$this->binds[$id] = $concrete;
		}
	}

	/**
	 * Remove identification from container
	 *
	 * @param string|array $id Identifier of the entry to look for.
	 */
	public function delete($id){
		foreach((array)$id as $name){
			$name = isset($this->name[$name]) ? $this->mappings[$name] : $name;

			if(isset($this->instances[$name])){
				unset($this->instances[$name]);
			}
		}
	}

	/**
	 * Returns true if the container can return an entry for the given identifier.
	 * Returns false otherwise.
	 * `has($id)` returning true does not mean that `get($id)` will not throw an exception.
	 * It does however mean that `get($id)` will not throw a `NotFoundExceptionInterface`.
	 *
	 * @param string $id Identifier of the entry to look for.
	 * @return bool
	 */
	public function has($id){
		return isset($this->instances[$id]);
	}

	/**
	 * 执行函数或者闭包方法 支持参数调用
	 *
	 * @access public
	 * @param mixed $function 函数或者闭包
	 * @param array $vars 参数
	 * @return mixed
	 * @throws \xin\container\ContainerException
	 * @throws \xin\container\NotFoundException
	 */
	public function invokeFunction($function, $vars = []){
		try{
			$reflect = new ReflectionFunction($function);

			$args = $this->bindParams($reflect, $vars);

			return call_user_func_array($function, $args);
		}catch(\ReflectionException $e){
			throw new NotFoundException('function not exists: '.$function.'()');
		}
	}

	/**
	 * 调用反射执行类的方法 支持参数绑定
	 *
	 * @access public
	 * @param mixed $method 方法
	 * @param array $vars 参数
	 * @return mixed
	 * @throws \xin\container\ContainerException
	 * @throws \xin\container\NotFoundException
	 */
	public function invokeMethod($method, $vars = []){
		try{
			if(is_array($method)){
				$class = is_object($method[0]) ? $method[0] : $this->invokeClass($method[0]);
				$reflect = new ReflectionMethod($class, $method[1]);
			}else{
				// 静态方法
				$reflect = new ReflectionMethod($method);
			}

			$args = $this->bindParams($reflect, $vars);

			return $reflect->invokeArgs(isset($class) ? $class : null, $args);
		}catch(ReflectionException $e){
			if(is_array($method) && is_object($method[0])){
				$method[0] = get_class($method[0]);
			}

			throw new NotFoundException('method not exists: '.(is_array($method) ? $method[0].'::'.$method[1] : $method).'()');
		}
	}

	/**
	 * 调用反射执行类的方法 支持参数绑定
	 *
	 * @access public
	 * @param object $instance 对象实例
	 * @param mixed  $reflect 反射类
	 * @param array  $vars 参数
	 * @return mixed
	 * @throws \xin\container\ContainerException
	 * @throws \xin\container\NotFoundException
	 */
	public function invokeReflectMethod($instance, $reflect, $vars = []){
		try{
			$args = $this->bindParams($reflect, $vars);
			return $reflect->invokeArgs($instance, $args);
		}catch(\ReflectionException $e){
			throw new ContainerException($e->getMessage(), $e->getCode(), $e);
		}
	}

	/**
	 * 调用反射执行callable 支持参数绑定
	 *
	 * @access public
	 * @param mixed $callable
	 * @param array $vars 参数
	 * @return mixed
	 * @throws \xin\container\ContainerException
	 * @throws \xin\container\NotFoundException
	 */
	public function invoke($callable, $vars = []){
		if($callable instanceof Closure){
			return $this->invokeFunction($callable, $vars);
		}

		return $this->invokeMethod($callable, $vars);
	}

	/**
	 * 调用反射执行类的实例化 支持依赖注入
	 *
	 * @access public
	 * @param string $class 类名
	 * @param array  $vars 参数
	 * @return mixed
	 * @throws \xin\container\ContainerException
	 * @throws \xin\container\NotFoundException
	 */
	public function invokeClass($class, $vars = []){
		try{
			$reflect = new ReflectionClass($class);

			if($reflect->hasMethod('__make')){
				$method = new ReflectionMethod($class, '__make');

				if($method->isPublic() && $method->isStatic()){
					$args = $this->bindParams($method, $vars);
					return $method->invokeArgs(null, $args);
				}
			}

			$constructor = $reflect->getConstructor();

			$args = $constructor ? $this->bindParams($constructor, $vars) : [];

			return $reflect->newInstanceArgs($args);
		}catch(ReflectionException $e){
			throw new ContainerException('class not exists: '.$class, $class);
		}
	}

	/**
	 * 绑定参数
	 *
	 * @access protected
	 * @param \ReflectionMethod|\ReflectionFunction $reflect 反射类
	 * @param array                                 $vars 参数
	 * @return array
	 * @throws \ReflectionException
	 * @throws \xin\container\ContainerException
	 * @throws \xin\container\NotFoundException
	 */
	protected function bindParams($reflect, $vars = []){
		if($reflect->getNumberOfParameters() == 0){
			return [];
		}

		// 判断数组类型 数字数组时按顺序绑定参数
		reset($vars);
		$type = key($vars) === 0 ? 1 : 0;
		$params = $reflect->getParameters();

		foreach($params as $param){
			$name = $param->getName();
			$lowerName = Loader::parseName($name);
			$class = $param->getClass();

			if($class){
				$args[] = $this->getObjectParam($class->getName(), $vars);
			}elseif(1 == $type && !empty($vars)){
				$args[] = array_shift($vars);
			}elseif(0 == $type && isset($vars[$name])){
				$args[] = $vars[$name];
			}elseif(0 == $type && isset($vars[$lowerName])){
				$args[] = $vars[$lowerName];
			}elseif($param->isDefaultValueAvailable()){
				$args[] = $param->getDefaultValue();
			}else{
				throw new InvalidArgumentException('method param miss:'.$name);
			}
		}

		return $args;
	}

	/**
	 * 获取对象类型的参数值
	 *
	 * @access protected
	 * @param string $className 类名
	 * @param array  $vars 参数
	 * @return mixed
	 * @throws \xin\container\ContainerException
	 * @throws \xin\container\NotFoundException
	 */
	protected function getObjectParam($className, &$vars){
		$array = $vars;
		$value = array_shift($array);

		if($value instanceof $className){
			$result = $value;
			array_shift($vars);
		}else{
			$result = $this->make($className);
		}

		return $result;
	}

	/**
	 * @param string $name
	 * @return mixed
	 */
	public function __get($name){
		return $this->get($name);
	}

	/**
	 * @param string $name
	 * @param mixed  $value
	 */
	public function __set($name, $value){
		$this->bind($name, $value);
	}

	/**
	 * @param string $name
	 * @return bool
	 */
	public function __isset($name){
		return $this->has($name);
	}

	/**
	 * @param string $name
	 */
	public function __unset($name){
		$this->delete($name);
	}

	/**
	 * Whether a offset exists
	 *
	 * @link https://php.net/manual/en/arrayaccess.offsetexists.php
	 * @param mixed $offset <p>
	 * An offset to check for.
	 * </p>
	 * @return boolean true on success or false on failure.
	 * </p>
	 * <p>
	 * The return value will be casted to boolean if non-boolean was returned.
	 * @since 5.0.0
	 */
	public function offsetExists($offset){
		return $this->has($offset);
	}

	/**
	 * Offset to retrieve
	 *
	 * @link https://php.net/manual/en/arrayaccess.offsetget.php
	 * @param mixed $offset <p>
	 * The offset to retrieve.
	 * </p>
	 * @return mixed Can return all value types.
	 * @since 5.0.0
	 */
	public function offsetGet($offset){
		return $this->get($offset);
	}

	/**
	 * Offset to set
	 *
	 * @link https://php.net/manual/en/arrayaccess.offsetset.php
	 * @param mixed $offset <p>
	 * The offset to assign the value to.
	 * </p>
	 * @param mixed $value <p>
	 * The value to set.
	 * </p>
	 * @return void
	 * @since 5.0.0
	 */
	public function offsetSet($offset, $value){
		$this->bind($offset, $value);
	}

	/**
	 * Offset to unset
	 *
	 * @link https://php.net/manual/en/arrayaccess.offsetunset.php
	 * @param mixed $offset <p>
	 * The offset to unset.
	 * </p>
	 * @return void
	 * @since 5.0.0
	 */
	public function offsetUnset($offset){
		$this->delete($offset);
	}
}
