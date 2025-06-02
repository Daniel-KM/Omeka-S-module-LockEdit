Lock Edit (module for Omeka S)
==============================

> __New versions of this module and support for Omeka S version 3.0 and above
> are available on [GitLab], which seems to respect users and privacy better
> than the previous repository.__

[Lock Edit ] is a module for [Omeka S] that warns automatically when another
user is editing a resource to avoid concurrent edition.


Installation
------------

See general end user documentation for [installing a module].

This module requires the module [Common], that should be installed first.

* From the zip

Download the last release [LockEdit.zip] from the list of releases, and
uncompress it in the `modules` directory.

* From the source and for development

If the module was installed from the source, rename the name of the folder of
the module to `LockEdit`.

Then install it like any other Omeka module and follow the config instructions.


Usage
-----

This feature is inspired by Drupal [Content Lock] mechanism and allows to block
concurrent editing: when a user is editing a resource, other users cannot edit
it until submission.

The default time out is 14400 seconds (four hours) and can be updated in main
settings.

In some cases, it is useful to disable the mechanism temporary, so a setting
allows it.


TODO
----

- [ ] Unlock when a user cancels an edition (via js or modify Cancel button to do a real cancel, not history back).


Warning
-------

Use it at your own risk.

It’s always recommended to backup your files and your databases and to check
your archives regularly so you can roll back if needed.


Troubleshooting
---------------

See online issues on the [module issues] page on GitLab.


License
-------

This module is published under the [CeCILL v2.1] license, compatible with
[GNU/GPL] and approved by [FSF] and [OSI].

This software is governed by the CeCILL license under French law and abiding by
the rules of distribution of free software. You can use, modify and/ or
redistribute the software under the terms of the CeCILL license as circulated by
CEA, CNRS and INRIA at the following URL "http://www.cecill.info".

As a counterpart to the access to the source code and rights to copy, modify and
redistribute granted by the license, users are provided only with a limited
warranty and the software’s author, the holder of the economic rights, and the
successive licensors have only limited liability.

In this respect, the user’s attention is drawn to the risks associated with
loading, using, modifying and/or developing or reproducing the software by the
user in light of its specific status of free software, that may mean that it is
complicated to manipulate, and that also therefore means that it is reserved for
developers and experienced professionals having in-depth computer knowledge.
Users are therefore encouraged to load and test the software’s suitability as
regards their requirements in conditions enabling the security of their systems
and/or data to be ensured and, more generally, to use and operate it in the same
conditions as regards security.

The fact that you are presently reading this means that you have had knowledge
of the CeCILL license and that you accept its terms.


Copyright
---------

* Copyright Daniel Berthereau, 2022-2025 (see [Daniel-KM] on GitLab)

This module was initially implemented in module [Easy Admin] and built for the
digital library [Numistral].


[Lock Edit]: https://gitlab.com/Daniel-KM/Omeka-S-module-LockEdit
[Omeka S]: https://omeka.org/s
[installing a module]: https://omeka.org/s/docs/user-manual/modules/
[LockEdit.zip]: https://github.com/Daniel-KM/Omeka-S-module-LockEdit/releases
[module issues]: https://gitlab.com/Daniel-KM/Omeka-S-module-LockEdit/issues
[Common]: https://gitlab.com/Daniel-KM/Omeka-S-module-Common
[Content Lock]: https://www.drupal.org/project/content_lock
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[Easy Admin]: https://gitlab.com/Daniel-KM/Omeka-S-module-EasyAdmin
[Numistral]: http://omeka.numistral.fr
[GitLab]: https://gitlab.com/Daniel-KM
[Daniel-KM]: https://gitlab.com/Daniel-KM "Daniel Berthereau"
