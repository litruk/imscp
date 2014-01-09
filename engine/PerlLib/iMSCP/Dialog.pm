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

package iMSCP::Dialog;

use strict;
use warnings;

use iMSCP::Debug;
use iMSCP::Execute;
use parent 'Common::SingletonClass';

sub factory
{
	my $self = iMSCP::Dialog->getInstance();

	unless($self->{'instance'}) {
		#my ($dialog, $whiptail, $rs, $stdout, $stderr, $file, $class);
		my ($dialog, $stdout, $stderr, $file, $class);

		if(! execute('which dialog', \$stdout, \$stderr)) {
			$file = "iMSCP/Dialog/Dialog.pm";
			$class = "iMSCP::Dialog::Dialog";
			require $file;
			$self->{'instance'} = $class->getInstance();
		# Commented out since whiptail implementation is not ready
		#} elsif(!execute('which whiptail', \$stdout, \$stderr)) {
		#	$file = "iMSCP/Dialog/Whiptail.pm";
		#	$class = "iMSCP::Dialog::Whiptail";
		#	require $file;
		#	$self->{instance} = $class->getInstance();
		#} else {
		#	fatal('Can not find whiptail or dialog. Please reinstall...');
		#}
		} else {
			fatal('Cannot find dialog program on your system. Please reinstall...');
		}

		$self->{'instance'}->set('title', 'i-MSCP Setup Dialog');
		$self->{'instance'}->set('backtitle', 'i-MSCP - internet Multi Server Control Panel');
	}

	$self->{'instance'};
}

sub reset
{
	my $self = iMSCP::Dialog->getInstance();

	$self->{'instance'} = undef;

	0;
}

1;
