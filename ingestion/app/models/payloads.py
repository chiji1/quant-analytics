from typing import List, Tuple, Optional
from pydantic import BaseModel, Field, ConfigDict

class DepthUpdatePayload(BaseModel):
    """
    Pydantic Model for Binance `@depth@100ms` stream.
    Validates and maps the raw single-letter JSON keys into readable Python attributes.
    """
    model_config = ConfigDict(populate_by_name=True)

    event_type: Optional[str] = Field(default="depthUpdate", alias="e")
    event_time: Optional[int] = Field(default=None, alias="E")
    symbol: Optional[str] = Field(default=None, alias="s")
    first_update_id: Optional[int] = Field(default=None, alias="U")
    final_update_id: Optional[int] = Field(default=None, alias="u")
    
    # Order book asks and bids are arrays of string tuples: ["Price", "Quantity"]
    bids: List[Tuple[str, str]] = Field(alias="b")
    asks: List[Tuple[str, str]] = Field(alias="a")
    velocity_delta: float = Field(default=0.0)
    velocity_ema: float = Field(default=0.0)
    tape_volume: float = Field(default=0.0)


class LiquidationOrderDetails(BaseModel):
    """
    Embedded details dictionary inside the forceOrder payload.
    """
    model_config = ConfigDict(populate_by_name=True)

    symbol: str = Field(alias="s")
    side: str = Field(alias="S")
    order_type: str = Field(alias="o")
    time_in_force: str = Field(alias="f")
    original_quantity: str = Field(alias="q")
    price: str = Field(alias="p")
    average_price: str = Field(alias="ap")
    order_status: str = Field(alias="X")
    last_filled_qty: str = Field(alias="l")
    accumulated_filled_qty: str = Field(alias="z")
    trade_time: int = Field(alias="T")


class LiquidationPayload(BaseModel):
    """
    Pydantic Model for Binance `@forceOrder` stream (Liquidations).
    """
    model_config = ConfigDict(populate_by_name=True)

    event_type: str = Field(alias="e")
    event_time: int = Field(alias="E")
    order: LiquidationOrderDetails = Field(alias="o")
    tape_volume: float = Field(default=0.0)


class SystemCommand(BaseModel):
    """
    Model strictly validating the administrative commands sent via 
    Redis Pub/Sub to control the ingestion engine remotely.
    """
    action: str  # Defines the action, e.g., 'reconnect', 'switch_env'
    target_env: Optional[str] = None  # Dynamic stream URL if switching environments
