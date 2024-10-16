# Ansible roles for Inuvika OVD Enterprise

![Inuvika Inc.](https://archive.inuvika.com/theme/media/logo-dark.png)

<https://www.inuvika.com>

The Ansible-ovd project is a set of Free and Open Source tools that let users install
and manage a full Inuvika OVD Enterprise environment on both Linux and Windows servers.

Ansible is used as the configuration management platform. Inuvika provides a collection
of Ansible roles that manage configuration of all OVD services, as well as an example playbook that
ties them together into a highly integrated environment.

Ansible roles and playbooks provided by Inuvika are designed to manage a single Proof of Concept (POC)
host or a set of hosts for production use. The hosts in question can be physical or virtual machines.

## Support

The roles support the following servers:

* Red Hat Enterprise Linux 7.x 64 bits (OVD <= 3.3)
* Red Hat Enterprise Linux 8.x 64 bits (OVD >= 3.2.1)
* CentOS 7.x 64 bits (OVD <= 3.3)
* Ubuntu 22.04 LTS server (Jammy Jellyfish) 64 bits (OVD >= 3.2.1)
* Ubuntu 18.04 LTS server (Bionic Beaver) 64 bits (OVD <= 3.3)
* Windows Server 2022
* Windows Server 2019
* Windows Server 2016
* Windows Server 2012 R2

Please ensure the virtual machine on which you are installing OVD meets the
system requirements outlined in the [Installation and Configuration Guide](https://docs.inuvika.com/installation_and_configuration_guide/).


## Install Ansible and Inuvika OVD roles

Installation of Inuvika OVD roles uses a Python Virtual Environment.

**Requirements**:

  * Git
  * Python 3
  * Python 3 venv module

While Ansible cannot run on a Windows host, it can manage
Windows hosts if executed on Windows under the Windows Subsystem
for Linux (WSL).

> The Windows Subsystem for Linux is not supported by Microsoft,
> Ansible, or Inuvika. Inuvika recommends that it not be used for
> production systems.

For more information about installing Ansible, please refer to the official
documentation at <https://docs.ansible.com/ansible/latest/index.html>.

 1. Clone the repository

   ```shell
   git clone https://github.com/inuvika/ansible-ovd
   ```

 2. Navigate to the directory

   ```shell
   cd ansible-ovd
   ```

 3. Create a Python Virtual Environment

   ```shell
   python3 -m venv py-env
   ```

 4. Load the Python Virtual Environment

   ```shell
   . py-env/bin/activate
   ```

 5. Update PIP

   ```shell
   pip install --upgrade pip
   ```

 6. Install the PIP dependencies

   ```shell
   pip install --upgrade -r requirements.pip
   ```

 7. When using ansible version >=2.10 (not default), install the Ansible
    Collection requirements

   ```shell
   ansible-galaxy collection install -r requirements.yml
   ```

## Version code

Throughout this document, download links will use a version code specific to the version
of OVD you are using.

You will find the *version code* on the [Inuvika OVD supported versions page](https://support.inuvika.com/portal/kb/articles/documentation).
You may also [contact Inuvika](https://www.inuvika.com/contact-us/) to request the code.

## Install and Manage your Inuvika OVD Enterprise farm

 1. Create a copy of the example folder and rename to a custom name

 1. Navigate to this folder

 1. Configure ssh key authentication
     1. Generate ssh keys with command `ssh-keygen`

     1. Copy the contents of the generated `.pub` file to Linux  servers in `~root/.ssh/authorized_keys` and `sudo chmod 600 ~root/.ssh/authorized_keys`.

 1. Update inventory variables in the inventory.cfg file
      * Replace `{VERSION_CODE}` with the *version code* as described
        in the [Version code section](#version-code "Version Code").

      * In *Linux servers authentication* section:
          * update the value for `ansible_user`.

          * if using *ssh key authentication*, comment out
            `ansible_password` line by prefixing it with `#`.

          * if `ansible_user` is set to *root*, set `ansible_become` to `no`.

          * if `ansible_user` is *not root*, update the values for:
              * `ansible_become_user` to specify the appropriate user.

              * `ansible_become_pass` to specify the password for privilege escalation.

      * In the section `[all]` add all your machines with:
        ```ini
        [all]
        machine_name ansible_host=ip_address
        ```

      * In the other sections, add the machine names to the features you want to install.

      * In the section `[woas:vars]` you can specify the specific credentials for the Windows Servers.

3. Run this powershell script to enable winrm on the Windows servers: [ConfigureRemotingForAnsible.ps1](https://raw.githubusercontent.com/ansible/ansible/devel/examples/scripts/ConfigureRemotingForAnsible.ps1)

4. Execute ansible:

   ```shell
   ansible-playbook ovd-farm.yml
   ```

## Contributing

If you are interested in fixing issues and contributing directly to the code base,
please see the document [How to Contribute](CONTRIBUTING.md).

## Feedback

* Ask a question at <support@inuvika.com>.
* [Tweet](https://twitter.com/InuvikaInc) us with any other feedback.

## License

Copyright (c) [Inuvika Inc.](https://www.inuvika.com) All rights reserved.

Licensed under the [Apache 2.0](LICENSE.md) License.

## About OVD Enterprise

OVD Enterprise is a virtualized application delivery platform for
any size commercial organization. It delivers virtualized Windows
and Linux applications, and shared desktops, to any endpoint device
using an Inuvika client or HTML 5 web browser. OVD installs on Linux
infrastructure hosted on-site, or on cloud platforms like Microsoft Azure
and Google Cloud Platform.

For more technical details, refer to our Reference Documents
section on <https://inuvika.com/support>.
