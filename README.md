#sidebar Sidebar 

== 2013-11-23: Serpent 2.0.0 (the latest and greatest) is now available. ==
  * made it PSR-0 compatible and Composer friendly (https://packagist.org/packages/mcsodbrenner/serpent) and added an PSR-0 autoloader for people which does not use Composer or an PSR-0 autoloader.
  * added specific Exceptions.
  * compilers are not longer plugins and not changeable. If you want to integrate other compilers just use mappings.
  * resources are not longer plugins and have to be injected with addResource().
  * own resources have to implement the interface \!McSodbrenner\Serpent\Resource.
  * in mappings you have now direct access to the parameters via the array $this->`_`mapping_parameters.
  * you can add your own mappings via addMappings(). Now it is also possible to use anonymous functions.
  * changed syntax for block. They are now also mappings, e.g. "~:block('content')~" and "~:endblock()~".
  * new mapping ":loop". It's like a for loop or a dynamic :repeat.
----

Serpent is a lightweight and compiling templating engine for PHP. It was designed to seamlessly integrate into existing MVC frameworks.

It uses PHP itself as its template language, so you do not need to learn a new markup language. On the other side you get many improvements compared to pure PHP.

*What it has: ([Overview Overview in Detail & Documentation])*
  * PSR-0 and Composer compatible
  * short syntax for php tags (shorter than PHPs own short tags wich are also possible)
  * no additional markup language
  * dot syntax for arrays (like Smarty)
  * shortcuts for your own functions
  * infinite horizontal and vertical template inheritance
  * definable resources
  * compiling engine for best performance
  * E_STRICT compatible
  * Unit tested

*What it does not have: ([MissingDetails Why it is missing those features])*
  * template security
  * caching system
  * overhead
