<?php

abstract class Memory {
	private $io;
	private $offset = 0;
	private $size = 0;
	private $type;

	public function SetIO( MemoryReaderIO $io ) {
		$this->io = $io;
	}
	public function SetOffset( $offset ) {
		$this->offset = $offset;
		return $this;
	}
	public function SetSize( $size ) {
		$this->size = $size;
		return $this;
	}

	public function GetIO() {
		return $this->io;
	}
	public function OffsetOf() {
		return $this->offset;
	}
	public function SizeOf() {
		return $this->size;
	}

	public function GetFullNameClass( $class ) {
		$class = trim( $class , "\\" );
		if ( class_exists( $_class = __NAMESPACE__ . "\\" . $class ) ) {
			return $_class;
		}
		if ( class_exists( $_class = $class ) ) {
			return $_class;
		}
		return false;
	}
	
	public function ParseTypeRaw($typeRaw) {
		$typeRaw = strtolower(trim($typeRaw));
		if ( preg_match("~^\*(.*)~", $typeRaw, $m) ) {
			if ( !strlen($nextTypeRaw = trim($m[1])) ) { $this->RiseError( "Expected next type, unxpected '*'" ); }
			return new MemoryPointer($this->GetIO(), $this->OffsetOf() + $this->SizeOf(), ['item' => $nextTypeRaw]);
		} elseif ( preg_match("~^\[(.*?)\](.*)~", $typeRaw, $m) ) {
			if ( !strlen($nextTypeRaw = trim($m[2])) ) { $this->RiseError( "Expected next type, unxpected '{$m[1]}'" ); }			
			$arrayCount = 0;
			eval( '$arrayCount=('.$m[1].');' );
			return new MemoryArray($this->GetIO(), $this->OffsetOf() + $this->SizeOf(), ['item' => $nextTypeRaw, "count" => $arrayCount]);
		} elseif ( preg_match("~^<(.*)~", $typeRaw, $m) ) {
			if ( strlen($nextTypeRaw = trim($m[1])) ) { $this->RiseError( "Unxpected '{$nextTypeRaw}'" ); }
			return new MemoryOffset($this->GetIO(), $this->OffsetOf() + $this->SizeOf());
		} elseif ( $_typeRaw = $this->GetFullNameClass($typeRaw) ) {
			return new $_typeRaw($this->GetIO(), $this->OffsetOf() + $this->SizeOf());
		} else {
			$this->RiseError( "Unxpected {$typeRaw}" );
		}
	}	
	
	public function RiseError( $text ) {
		throw new \Exception($text);
	}
	
	public function __construct(MemoryReaderIO $io, $offset = 0, $params = []) {
		$this->SetIO($io);
		$this->SetOffset($offset);
		if ( is_callable([$this,"onConstruct"]) ) {
			$this->onConstruct( $params );
		}
	}
}

class MemoryBase extends Memory {
	public function Get() {
		return $this->GetIO()->{"Read".$this->method}( $this->OffsetOf() );
	}
	public function Set( $value ) {
		$this->GetIO()->{"Write".$this->method}( $this->OffsetOf() , $value );
		return $this;
	}

	private $method;
	public function onConstruct() {
		$l = explode("\\", get_class($this));
		$this->method = $l[ count($l)-1 ];
		if ( preg_match("~\d*$~", $this->method, $m) ) {
			$this->SetSize($m[0] >> 3);
		}
	}
	
	public function __get($name) {
		$name = strtolower($name);
		return $this->Get();
	}
	public function __set($name, $value) {
		$name = strtolower($name);
		$this->Set($value);
		return $this;
	}
}
final class UInt8 extends MemoryBase {}
final class UInt16 extends MemoryBase {}
final class UInt32 extends MemoryBase {}
final class Int8 extends MemoryBase {}
final class Int16 extends MemoryBase {}
final class Int32 extends MemoryBase {}

final class MemoryPointer extends Memory {
	private $item;
	private $itemRaw;
	public function Set( $value ) {
		$this->GetIO()->WriteUInt32( $this->OffsetOf() , $value );
		return $this;
	}
	public function Get() {
		return $this->GetIO()->ReadUInt32( $this->OffsetOf() );
	}
	public function onConstruct( $params ) {
		$this->SetSize(4);
		$this->itemRaw = $params['item'];
	}
	public function To() {
		if ( !$this->item ) {
			$this->item = $this->ParseTypeRaw( $this->itemRaw );
		}
		$this->item->SetOffset( $this->Get() );
		return $this->item;
	}
	
	public function __get($name) {
		$name = strtolower($name);
		return $this->To();
	}
	public function __set($name, $value) {
		$name = strtolower($name);
		$this->To()->Set($value);
		return $this;
	}
	public function __invoke() {
		return $this->To();
	}
}

final class MemoryArray extends Memory implements \ArrayAccess {
	private $item;
	private $count;

	public function CountOf() {
		return $this->count;
	}
	
	private function __checkIndex(&$index) {
		$index = (int)$index;
		return !( $index < 0 || $index >= $this->count );
	}
	private function __tryIndex(&$index) {
		if ( !$this->__checkIndex($index) ) { $this->riseError("Index {$index} of bounds error"); }
	}


	public function Get() {
		return $this;
	}
	public function Set($value) {
		if ( ( !$value instanceof Memory ) ) {
			$this->riseError( "Error type value" );
		}
		if ( $value->SizeOf() !== $this->SizeOf() ) {
			$this->riseError( "Error size value({$value->SizeOf()}), need({$this->SizeOf()})" );
		}
		$this->GetIO()->Write($this->OffsetOf(), $value->GetIO()->OffsetOf());
	}
	
    public function offsetExists($index) {
		return $this->__checkIndex($index);
    }
    public function offsetGet($index) {
		$this->__tryIndex($index);
		$this->item->SetOffset($this->OffsetOf() + $index * $this->item->SizeOf());
		if ( $this->item instanceof MemoryPointer ) {
			return $this->item;
		}
		return $this->item->Get();
    }
    public function offsetSet($index, $value) {
		$this->__tryIndex($index);
		$this->item->SetOffset($this->OffsetOf() + $index * $this->item->SizeOf());
		$this->item->Set($value);
    }
    public function offsetUnset($offset) {
    }
	
	public function onConstruct($params) {
		$this->item = $this->ParseTypeRaw( $params['item'] );
		$this->count = $params['count'];
		$this->SetSize( $this->item->SizeOf() * $this->count );
	}
}

final class MemoryOffset extends Memory {
	public function Set( $value ) {
	}
	public function Get() {
		return $this->OffsetOf();
	}

	public function onConstruct() {
		$this->SetSize(0);
	}
}

class MemoryStructure extends Memory {
	private $propertiesRaw;
	private $properties;
	private $propertiesOffsets;
	public function onConstruct() {
		$this->__PropetiesParseRaw();
		$this->__PropetiesParse();
	}
	
	private function __PropetiesParseRaw() {
		$this->propertiesRaw = [];
		foreach((array)$this as $property => $type) {
			if ( $property[0] === "\x00" ) { continue; }
			$this->propertiesRaw[ $property ] = $type;
			unset($this->{$property});
		}
	}
	private function __PropetiesParse() {
		$this->properties = [];
		$this->propertiesOffsets = [];
		foreach($this->propertiesRaw as $propertyRaw => $typeRaw) {
			$this->__PropetyParse($propertyRaw, $typeRaw);
		}
	}
	private function __PropetyParse($name, $typeRaw) {
		$name = strtolower(trim($name));
		$typeRaw = strtolower(trim($typeRaw));

		$this->properties[$name] = $type = $this->ParseTypeRaw($typeRaw);
		$this->propertiesOffsets[$name] = $this->SizeOf();
		$this->SetSize( $this->SizeOf() + $type->SizeOf() );
	}

	public function __get($name) {
		$name = strtolower($name);
		if ( isset( $this->properties[$name] ) ) {
			$clone = clone $this->properties[$name];
			$clone->SetOffset( $this->OffsetOf() + $this->propertiesOffsets[$name] );
			return $clone->Get();
		}
	}
	public function __set($name, $value) {
		$name = strtolower($name);
		if ( isset( $this->properties[$name] ) ) {
			$clone = clone $this->properties[$name];
			$clone->SetOffset( $this->OffsetOf() + $this->propertiesOffsets[$name] );
			return $clone->Set($value);
		}
	}
	public function __call($name, $args) {
		$name = strtolower($name);
		if ( isset( $this->properties[$name] ) ) {
			$clone = clone $this->properties[$name];
			$clone->SetOffset( $this->OffsetOf() + $this->propertiesOffsets[$name] );
			if ( $clone instanceof MemoryPointer ) {
				return $clone->To();
			}
			return $clone;
		}
	}
	
	public function Get() {
		return $this;
	}
	public function Set($value) {
		return $this;
	}
}
