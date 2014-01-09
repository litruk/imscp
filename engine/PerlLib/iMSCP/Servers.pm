#!/usr/bin/perl

# i-MSCP - internet Multi Server Control Panel
# Copyright (C) 2010-2014 by internet Multi Server Control Panel
#
# This program is free software; you can redistribute it and/or
# modify it under the terms of the GNU General Public License
# as published by the Free Software Foundation; either version 2
# of the License, or (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with this program; if not, write to the Free Software
# Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
#
# @category    i-MSCP
# @copyright   2010-2014 by i-MSCP | http://i-mscp.net
# @author      Daniel Andreca <sci2tech@gmail.com>
# @link        http://i-mscp.net i-MSCP Home Site
# @license     http://www.gnu.org/licenses/gpl-2.0.html GPL v2

package iMSCP::Servers;

use strict;
use warnings;

use iMSCP::Debug;
use iMSCP::Dir;
use parent 'Common::SingletonClass';

sub load
{
	my $self = shift;

	my $servers	= iMSCP::Dir->new('dirname' => "$main::imscpConfig{'ENGINE_ROOT_DIR'}/PerlLib/Servers");

	@{$self->{'servers'}} = $servers->getFiles();

	debug("Returning: @{$self->{'servers'}}");

	0;
}

sub get
{
	my $self = shift;

	$self->load() unless exists $self->{'servers'};

	return (exists $self->{'servers'} ? @{$self->{'servers'}} : ());
}

1;
