JSON API Consumer
=================

Requirements
------------
 - SilverStripe 3.x

Installation
------------
 - Put folder into SilverStripe root
 - dev/build

Usage
-----
###Basics
 * Define your API spec with a config file.
 * Use it
   - Create with: `YourAPIClass::set($endpoint, null, $data)`
   - Read   with: `YourAPIClass::get($endpoint [, $record_id [, $query_filters]])`
   - Update with: `YourAPIClass::set($endpoint, $record_id, $data)`
   - Delete with: ???
 * Debug it with GET url param debug_api=1
 * See errors (HTML this is) via GET url param show_api_errors=1 (and =0 to disable it again, as it sets a session var)
###Config
The system takes a basic config with only three options. However those options have multiple and varied complex setups of their own.
 1. **base**
 2. **auth**
 3. **endpoints**
####base
This is the base point of all requests. The domain of your api, as such.
This can either be a single string ()eg. `http://theapisdomain.tld/api/v1/`) which will be used under all environment settings.
Or this can be set by environment, eg:
```yml
YourAPI:
  base:
    live: http://apidomain.tld/api
    dev: http://dev.apidomain.tld/testdata
    test: http://idontknow.if/anyone_ever_uses_test_as_an/environment_setting
```
####auth
This option takes only a simple string. This string _must be a valid HTTP header_ in construction though.
Eg. `"X-apiauth: 12345"`
####endpoints
The meat of it all. Can get quite complex. Sub endpoints are a thing.
The URL parameter is of course required, which is relative to the base specified above. However the entire endpoint can be simplified down to simply `Name: url` if all the other options are OK as default.
All endpoint config options and their defaults are listed below:

 - **`url: 'required/$GUID'`** reasonably self explanitory. relative to *base* (eg. this would become _example.tld/required/_ or _example.tld/required/42_). 
   - Follows the same definition style as SilverStripe route definitions - so $GUID! would make the variable required. Of course variables earlier in the endpoint path will always be required.
   - Similarly 'redirects' can be defined by reference as ->Other.Endpoint (which needs to be a validly defined endpoint, of course) - in this way one can alias their endpoints (in case the existing structure makes little sense to the developer).
 - `expects: 'auto'` what must be supplied into the endpoint (when pushing ie. to force JSON array encode).
 - `returns: 'auto'` define what is returned by this endpoint (list[array], object, customClass[by name])
 - `methods: ['GET']` what is valid for this endpoint (ie. HTTP methods)
 - `params: {}` for GET/pull points, are there any filters that can be passed in (eg. search=keyword)
 - `duplex: {}` *sub endpoints* that can be both pulled from and pushed to
 - `pull: {}` *sub endpoints* that can only be read from
 - `push: {}` *sub endpoints* that can only have data put into (POST/PUT)
 - `cache: 0` how long should curl cache this for? (curl cache persists beyond request processing)

*Sub endpoints* are basically recursive endpoint definitions in an indexed array (non keyed values), so could be defined as 'Subthing: more' which would concatenate to end up as: `"$base/$parent/more/"` which could only have GET used on it, and is accessed as "Parent.Subthing". Similarly 'pull' denotes only POST/PUT endpoints. 'duplex' is the natural 2 way endpoint.
Furthermore a definition of `pull: /another` would override the parent to become: `"$base/another"` in the same manner that relative links work in HTML.

--Typing this out now it's obvious that I've added the 'methods' config option at a later point in a need to be able to define push/pull on a parent root level endpoint. This (c|sh)ould probably be reworked to make all sub endpoints come under a single definition (eg. 'subs') and define 'methods' if reduction in permissions is needed. 'methods' gives finer grained control also, as one can cut out Create but still allow Update, where as 'push' does not.

####A more full(ish) example
```yml
---
Name: ArtHistoryAPI
After: 'framework/*','cms/*'
Before: 'mysite/config'
---
ArtAPI:
  base: http://famousart.fake/api
  auth: "X-apikey: n0n53n53"
  endpoints:
    User: user/$GUID
    Users: ->User
    Artists:
      url: artists/$GUID
      methods:
        - GET
      params:
        - year
        - style
      pull:
        Works: paintings/$GUID
        MoreInfo: /paintinginfo/$GUID!
      push:
        Update: /updateartist/$GUID
```
#####Example's Usage
```php
//all artists active in 1888
ArtAPI::get('Artists', null, ['year'=>1888]); //End up with APIList (basically ArrayList).

//where id 53 might be Vincent van Gogh, get all his paintings.
ArtAPI::get('Artists.Works', 53); //End up with APIList (basically ArrayList).

//get a single painting's info.
//Artist's ID is also required as it's part of the URL (an exception is thrown otherwise).
ArtAPI::get('Artists.Works', [53,24]); //End up with APIData (basically ArrayData).

//Exception thrown - GUID is required.
ArtAPI::get('Artists.MoreInfo'); 

//create super secure John Doe admin user
ArtAPI::set('User', null, array('name'=>'John Doe','admin'=>true,'password'=>false));

//update the description on painting 24.
ArtAPI::set('Artists.UpdateInfo', 24, [
	'shortDesc' =>
		'One of his most famous paintings, van Gogh's'.
		'Cafe Terrace at Night was painted mid 1888 in France.'
]);

//Exception thrown (we can only update, not put new works).
ArtAPI::set('Artists.Update', null, ['title'=>'The Persistence of Memory']); 
```


About
-----
Some time in 2013 I needed to interface with an external database (for which we had no access to) which held all user information (including all objects that related to the user), where as only the odd page would be handled by SilverStripe. I attempted to write a Security adaptor to authenticate a user, but it wasn't a good solution. Nor was it to enable me to fetch all the related objects (eg. employer, vehicles & properties owned, as a made up unrelated example).

The interface for this database was to be a JSON API (actively being developed in parallel by a third party), and I also didn't want to be fiddling around and updating termination points in each source file where applicable (eg. as per a recent [blog post](http://takeaway.bigfork.co.uk/working-with-external-data-sources-in-silverstripe-pt2) with a simple example). I am using SilverStripe, and I want to keep things (ie. data access) consistent. So I built this. It's rough. It's very rough, but it did the job and was completed within a constrained time frame (aren't all things?).

###Current Assumptions
 * You could be working with a .NET implementation. Probably via Microsoft Dynamics CRM/AX (so date parsing is funny)
 * The RESTful API implementation isn't really all that truly RESTful (some might call it RESTless).
 * Some endpoints are GET only, some are POST only.
 * When you POST, you supply JSON data.
 * As the above are true, PUT and DELETE aren't things.
 * The only parameter you're going to feed to your endpoints is a GUID (sometimes in multiple!)
 * The RESTful JSON API implementation won't always return JSON.
 * Straight HTML _might_ be returned on error (with a 200 OK, just to be a prick).
 * API authentication/authorisation keys are simply set as custom HTTP headers.
 * A single request takes ... unacceptably long to complete. So we need some basic caching.

Notes
-----
 - Ensure you've read the Assumptions
 - When things go wrong, `throw Exception`s rather than waiting for things to die obscurely down the track
 - Some options exist to deal with the idiosyncrasies that came with the circumstances of the original inception. However this should not obscure the development of a more pure RESTful service consumer, to such that I don't foresee the API changing that much (being added to rather than undergoing alterations).

TODO:
-----
 1. [ ] Obviously convert example JSON into actual example!
 2. [ ] Convert example classes into actual examples instead of being all actual production code.
 3. [ ] Docs! I don't even know what all the config things are anymore!
 4. [ ] Stop this sillyness of needing a class per API. Use the config better.
 5. [ ] Probably the API List and Data stuff could be a little better (namespaces?)
 6. [ ] Auto query relation infos from API Datas just like DataObject
 7. [ ] Needs test suite
 8. [ ] OMG Clean it all up!
