<?php

namespace WCPay\Core\State_Machine;
abstract class State_Machine_Abstract {
	abstract public function get_id(): string;

	/**
	 * The transaction configuration.
	 *
	 * @var array
	 */
	protected $config = [];

	/** @var null | Input */
	private $input = null;

	/**
	 * @var Happy_State | Async_State
	 */
	private $initial_state;

	/** @var Entity_Payment */
	private $entity;
	public function __construct( Entity_Storage_Payment $storage ) {
		$this->storage       = $storage;
	}


	public function set_input( Input $input ) {
		$this->input = $input;
		return $this;
	}

	public function set_initial_state( State $initial_state ) {
		$this->initial_state = $initial_state;
		return $this;
	}

	public function set_entity( Entity_Payment $entity ) {
		$this->entity = $entity;
		return $this;
	}
	public function progress(): Entity_Payment {

		if ( ! $this->entity ) {
			throw new \Exception( 'Entity not set' );
		}

		$current_state = $this->initial_state ?? $this->entity->get_current_state();

		if ( ! $current_state ) {
			throw new \Exception( 'Initial state is not set, or there is no current state' );
		}

		do {
			$next_state = $current_state->act( $this->entity, $this->input);

			if( ! $this->is_valid_next_state($current_state, $next_state) ) {
				// TODO - create a separate exception.
				throw new \Exception( 'Invalid next state: ' . $next_state->get_id() . ' from previous state: ' . $current_state->get_id() );
			}

			// Log the transition.
			$this->entity->log( $current_state, $next_state, $this->input, $this );
			$current_state = $next_state;

		} while ( ! $this->is_emit_state( $current_state ) );

		return $this->entity;
	}

	protected function is_valid_next_state( State $current_state, State $next_state): bool {
		$current_state_class = get_class( $current_state );
		$next_state_class = get_class( $next_state );
		return in_array( $next_state_class, $this->config[ $current_state_class ] ) ;
	}

	protected function is_emit_state( State $state): bool {
		return is_subclass_of( $state, Final_State::class )
			|| is_subclass_of( $state, Failed_State::class )
			|| is_subclass_of( $state, Async_State::class );
	}
}
