from pydantic import BaseModel, Field, ConfigDict
import json

class DepthUpdatePayload(BaseModel):
    model_config = ConfigDict(populate_by_name=True)
    event_type: str = Field(default="depthUpdate", alias="e")
    bids: list = Field(alias="b")
    asks: list = Field(alias="a")

data = {"bids": [["1", "2"]], "asks": [["3", "4"]]}
try:
    obj = DepthUpdatePayload.model_validate(data)
    print("SUCCESS:")
    print(obj.model_dump_json(by_alias=True))
except Exception as e:
    print("ERROR:")
    print(e)
