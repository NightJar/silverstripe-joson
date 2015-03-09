<?php

/*
Get up and running fast example:

//Define your api
class YourAPIProvider extends JSONAPI {}

#use _config to specify your APIs in yaml
YourAPIProvider:
  base: 'https://thatAPIprovider.domain/example'
  endpoints:
    test: pathRelativeToBase

//Access via static calls
public function Things() { return YourAPIProvider::get('test'); }

<%-- output a property on an object within an array returned by the endpoint --%>
<% loop $Things %>$Property<% end_loop %>
*/
